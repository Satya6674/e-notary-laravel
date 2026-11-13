<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Module 12: ticket attachments. PDF, JPG and PNG only, judged by content as well as name,
 * 3 files of 5 MB at most, private storage, and downloads for the requester and Admins only.
 * Real temporary files are used: Laravel's fake uploads report a type from the name alone.
 */
class SupportAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Document::PRIVATE_DISK);
    }

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function user(string $role = 'public'): User
    {
        $this->n++;
        $user = User::create([
            'name' => "Attach {$this->n}", 'email' => "attach{$this->n}@example.test", 'mobile' => '93400'.str_pad((string) $this->n, 5, '0', STR_PAD_LEFT),
            'password' => Hash::make('demo-pass-123'), 'role' => $role, 'email_verified_at' => now(),
        ]);
        $user->forceFill(['mobile_verified_at' => now(), 'notary_approved_at' => $role === 'notary' ? now() : null])->save();

        return $user;
    }

    /** A real file with the given bytes, uploaded under the given name. */
    private function file(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($path, $bytes);
        $this->temp[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private function pdf(string $name = 'receipt.pdf'): UploadedFile
    {
        return $this->file($name, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    }

    private function png(string $name = 'screen.png'): UploadedFile
    {
        return $this->file($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
    }

    private function open(User $user, array $files, array $extra = [])
    {
        return $this->actingAs($user)->post(route('support.store'), $extra + [
            'subject' => 'Upload problem', 'category' => 'documents', 'body' => 'Screenshot attached.', 'attachments' => $files,
        ]);
    }

    public function test_a_requester_attaches_files_and_only_they_and_admins_can_download_them(): void
    {
        $citizen = $this->user();
        $other = $this->user();
        $notary = $this->user('notary');
        $admin = $this->user('admin');

        $this->open($citizen, [$this->pdf('my receipt.pdf'), $this->png()])->assertRedirect();
        $ticket = SupportTicket::sole();
        $files = SupportTicketAttachment::orderBy('id')->get();

        $this->assertCount(2, $files);
        $this->assertSame(['my receipt.pdf', 'screen.png'], $files->pluck('original_name')->all());
        $this->assertSame(['application/pdf', 'image/png'], $files->pluck('mime_type')->all());
        foreach ($files as $file) {
            Storage::disk(Document::PRIVATE_DISK)->assertExists($file->path);
            $this->assertStringStartsWith('support/', $file->path);
            $this->assertStringNotContainsString('receipt', $file->path, 'stored under a random name');
            $this->assertSame(64, strlen($file->sha256));
        }
        $this->assertSame(2, AuditLog::where('action', 'ticket.opened')->sole()->meta['attachments']);

        $url = route('support.attachment', [$ticket, $files[0]->id]);
        $this->actingAs($citizen)->get(route('support.show', $ticket))->assertOk()->assertSee($url, false)->assertSee('my receipt.pdf');

        $download = $this->actingAs($citizen)->get($url)->assertOk();
        $this->assertStringContainsString('attachment; filename=my-receipt.pdf', (string) $download->headers->get('Content-Disposition'));
        $download->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('sandbox', (string) $download->headers->get('Content-Security-Policy'));

        $this->actingAs($admin)->get($url)->assertOk();
        $this->actingAs($other)->get($url)->assertForbidden();
        $this->actingAs($notary)->get($url)->assertForbidden();
        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));

        $this->assertSame(2, AuditLog::where('action', 'ticket.attachment_downloaded')->count());
    }

    public function test_an_attachment_cannot_be_read_through_another_ticket(): void
    {
        $alice = $this->user();
        $bob = $this->user();
        $this->open($alice, [$this->pdf()]);
        $aliceFile = SupportTicketAttachment::sole();
        $this->open($bob, [], ['subject' => 'Bob ticket']);
        $bobTicket = SupportTicket::where('user_id', $bob->id)->sole();

        $this->actingAs($bob)->get(route('support.attachment', [$bobTicket, $aliceFile->id]))->assertNotFound();
    }

    public function test_disguised_oversized_and_too_many_files_are_refused_and_nothing_is_stored(): void
    {
        $citizen = $this->user();

        // A script renamed to .pdf: the content is checked, not the name.
        $this->open($citizen, [$this->file('invoice.pdf', "<?php echo 'x'; ?>\n")])->assertSessionHasErrors('attachments.0');
        // An HTML page renamed to .png.
        $this->open($citizen, [$this->file('photo.png', '<html><script>alert(1)</script></html>')])->assertSessionHasErrors('attachments.0');
        // A real PDF with a name that is not allowed.
        $this->open($citizen, [$this->pdf('report.exe')])->assertSessionHasErrors('attachments.0');
        // A Word file is not accepted here, even though documents accept it.
        $this->open($citizen, [$this->file('letter.docx', "PK\x03\x04".str_repeat('x', 100))])->assertSessionHasErrors('attachments.0');
        // Larger than 5 MB.
        $this->open($citizen, [$this->file('big.pdf', "%PDF-1.4\n".str_repeat('0', 5 * 1024 * 1024 + 10))])->assertSessionHasErrors('attachments.0');
        // Four files.
        $this->open($citizen, [$this->pdf('a.pdf'), $this->pdf('b.pdf'), $this->pdf('c.pdf'), $this->pdf('d.pdf')])->assertSessionHasErrors('attachments');

        $this->assertSame(0, SupportTicket::count());
        $this->assertSame(0, SupportTicketAttachment::count());
        $this->assertSame([], Storage::disk(Document::PRIVATE_DISK)->allFiles('support'));
    }

    public function test_a_failed_save_leaves_no_orphan_file(): void
    {
        $citizen = $this->user();
        $ticket = SupportTicket::create(['ticket_number' => 'ST-T-1', 'user_id' => $citizen->id, 'subject' => 'x', 'category' => 'other']);

        // Make the attachment insert fail inside the transaction. A database error is raised as the
        // row is written; replacing the table instead would not work on MariaDB, where DDL commits
        // the test's transaction and Laravel's savepoint disappears.
        SupportTicketAttachment::creating(function () {
            throw new \Illuminate\Database\QueryException('mysql', 'insert into support_ticket_attachments', [], new \PDOException('Synthetic insert failure'));
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($citizen)->post(route('support.reply', $ticket), ['body' => 'With a file', 'attachments' => [$this->pdf()]]);
            $this->fail('The insert should have failed.');
        } catch (\Illuminate\Database\QueryException) {
        }

        $this->assertSame([], Storage::disk(Document::PRIVATE_DISK)->allFiles('support'), 'the stored file was removed again');
        $this->assertSame(0, $ticket->messages()->count(), 'the message was rolled back too');
    }

    public function test_a_changed_or_missing_file_is_not_served(): void
    {
        $citizen = $this->user();
        $this->open($citizen, [$this->pdf()]);
        $file = SupportTicketAttachment::sole();
        $url = route('support.attachment', [$file->ticket_id, $file->id]);

        Storage::disk(Document::PRIVATE_DISK)->put($file->path, '%PDF-1.4 tampered');
        $this->actingAs($citizen)->get($url)->assertNotFound();

        Storage::disk(Document::PRIVATE_DISK)->delete($file->path);
        $this->actingAs($citizen)->get($url)->assertNotFound();
    }

    public function test_replies_can_carry_files_but_a_closed_ticket_takes_none(): void
    {
        $citizen = $this->user();
        $admin = $this->user('admin');
        $this->open($citizen, []);
        $ticket = SupportTicket::sole();

        $this->actingAs($admin)->post(route('support.reply', $ticket), ['body' => 'Here is the form', 'attachments' => [$this->pdf('form.pdf')]])->assertSessionHas('status', 'Reply sent.');
        $this->assertSame($admin->id, (int) SupportTicketAttachment::sole()->user_id);

        $this->actingAs($citizen)->post(route('support.close', $ticket));
        $this->actingAs($citizen)->post(route('support.reply', $ticket), ['body' => 'Late file', 'attachments' => [$this->pdf('late.pdf')]])
            ->assertSessionHas('error', 'This ticket is closed.');
        $this->assertSame(1, SupportTicketAttachment::count());
        $this->assertCount(1, Storage::disk(Document::PRIVATE_DISK)->allFiles('support'));
    }

    public function test_before_the_migration_the_field_is_hidden_and_uploads_are_refused(): void
    {
        $citizen = $this->user();
        DB::statement('DROP TABLE support_ticket_attachments');
        SupportTicketAttachment::forgetSchemaState();

        try {
            $this->actingAs($citizen)->get(route('support.index'))->assertOk()->assertDontSee('name="attachments[]"', false);
            $this->open($citizen, [$this->pdf()])->assertSessionHasErrors('attachments');
            $this->open($citizen, [])->assertRedirect();
            $ticket = SupportTicket::sole();
            $this->actingAs($citizen)->get(route('support.show', $ticket))->assertOk();
            $this->actingAs($citizen)->get(route('support.attachment', [$ticket, 1]))->assertNotFound();
        } finally {
            SupportTicketAttachment::forgetSchemaState();
        }
    }
}
