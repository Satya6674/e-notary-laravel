<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\NotaryRequest;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Facts only a real MariaDB can confirm. SQLite ignores column types, ON UPDATE and
 * length limits, so these skip there. Run them with scripts\Test-MariaDB.ps1.
 *
 * Evidence level: REAL MariaDB, on a throwaway database. No provider is involved.
 */
class MariaDbCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Needs MariaDB: run scripts\Test-MariaDB.ps1.');
        }
    }

    private function column(string $table, string $name): object
    {
        return DB::selectOne(
            'select column_type, extra, is_nullable from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?',
            [$table, $name]
        );
    }

    private function document(array $extra = []): Document
    {
        static $i = 0;
        $i++;

        $user = User::create([
            'name' => "M{$i}", 'email' => "maria{$i}@example.test", 'mobile' => '9700000'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'password' => Hash::make('x'), 'role' => 'public',
        ]);

        $application = NotaryRequest::create([
            'request_number' => NotaryRequest::generateRequestNumber(), 'user_id' => $user->id,
            'document_type' => 'will', 'purpose' => 'x', 'status' => 'pending',
        ]);

        return Document::create(array_merge(['request_id' => $application->id, 'title' => 'T', 'original_file' => "documents/{$i}.pdf"], $extra));
    }

    public function test_this_really_is_mariadb(): void
    {
        $this->assertStringContainsString('MariaDB', DB::selectOne('select version() as v')->v);
        $this->assertSame('enotary_phpunit', DB::selectOne('select database() as d')->d);
    }

    public function test_new_date_columns_cannot_update_themselves(): void
    {
        foreach ([['documents', 'superseded_at'], ['users', 'pending_mobile_requested_at']] as [$table, $name]) {
            $column = $this->column($table, $name);

            $this->assertSame('datetime', $column->column_type, "{$table}.{$name}");
            $this->assertStringNotContainsStringIgnoringCase('on update', $column->extra);
            $this->assertSame('YES', $column->is_nullable);
        }
    }

    public function test_saving_a_document_leaves_its_superseded_date_alone(): void
    {
        $document = $this->document();

        $document->update(['title' => 'Changed']);

        $this->assertNull($document->fresh()->superseded_at);
    }

    public function test_a_version_can_have_only_one_successor(): void
    {
        $old = $this->document();
        $this->document(['replaces_document_id' => $old->id]);

        $this->expectException(QueryException::class);

        $this->document(['replaces_document_id' => $old->id]);
    }

    public function test_the_version_link_must_point_at_a_real_document(): void
    {
        $this->expectException(QueryException::class);

        $this->document(['replaces_document_id' => 999999]);
    }

    public function test_many_first_versions_can_coexist(): void
    {
        $this->document();
        $this->document();

        $this->assertSame(2, Document::whereNull('replaces_document_id')->count());
    }

    public function test_removing_an_old_version_outright_keeps_the_newer_one(): void
    {
        $old = $this->document();
        $new = $this->document(['replaces_document_id' => $old->id]);

        $old->forceDelete();

        $this->assertNull($new->fresh()->replaces_document_id);
    }

    public function test_a_pending_number_fits_the_same_size_as_a_number(): void
    {
        $this->assertSame($this->column('users', 'mobile')->column_type, $this->column('users', 'pending_mobile')->column_type);
    }

    public function test_pending_numbers_are_not_unique(): void
    {
        $this->document();
        User::query()->update(['pending_mobile' => '9700000999']);

        $user = User::create(['name' => 'Second', 'email' => 'second@example.test', 'mobile' => '9700000998', 'password' => 'x', 'role' => 'public']);
        $user->forceFill(['pending_mobile' => '9700000999'])->save();

        $this->assertSame(2, User::where('pending_mobile', '9700000999')->count());
    }

    /**
     * Audit B-12: a required TIMESTAMP silently gains ON UPDATE CURRENT_TIMESTAMP on MariaDB, so
     * the value changes on every save. Only updated_at may behave like that, in any table.
     */
    public function test_no_column_but_updated_at_changes_itself_on_update(): void
    {
        $columns = DB::select("SELECT table_name AS t, column_name AS c FROM information_schema.columns
            WHERE table_schema = DATABASE() AND extra LIKE '%on update%' AND column_name <> 'updated_at'");

        $this->assertSame([], array_map(fn ($r) => "{$r->t}.{$r->c}", $columns));
    }

    public function test_the_earlier_fixes_this_work_relies_on_hold(): void
    {
        $this->assertStringNotContainsStringIgnoringCase('on update', $this->column('otp_verifications', 'expires_at')->extra);
        $this->assertSame('varchar(255)', $this->column('documents', 'mime_type')->column_type);
    }
}
