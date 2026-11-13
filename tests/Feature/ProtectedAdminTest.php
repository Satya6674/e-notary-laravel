<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\ProtectedAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The owner's permanent Admin (config/accounts.php) cannot be deleted, deactivated or edited by
 * another administrator. Found in the 2026-11-04 rehearsal: a second Admin could delete it.
 */
class ProtectedAdminTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'owner-pass-123';

    private function admin(string $email, string $name): User
    {
        static $n = 0;
        $n++;
        $user = User::create(['name' => $name, 'email' => $email, 'mobile' => '95700'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'password' => Hash::make(self::PASSWORD), 'role' => 'admin']);

        return $user;
    }

    public function test_another_admin_cannot_delete_deactivate_or_edit_the_permanent_admin(): void
    {
        config(['accounts.protected' => ['owner@example.test']]);
        $owner = $this->admin('Owner@Example.test', 'Owner');
        $other = $this->admin('second.admin@example.test', 'Second Admin');
        $before = $owner->fresh()->only(['email', 'password', 'role', 'deactivated_at', 'deleted_at']);

        $this->assertTrue($owner->isProtectedAccount(), 'matched without case');
        $this->assertFalse($other->isProtectedAccount());

        $this->actingAs($other)->delete(route('admin.users.delete', $owner), ['reason' => 'Trying to delete the owner.', 'confirm' => '1'])
            ->assertRedirect();
        $this->actingAs($other)->delete(route('admin.users.destroy', $owner), ['reason' => 'Trying to deactivate the owner.'])
            ->assertSessionHas('error', 'This is the permanent administrator account. It cannot be deactivated.');
        $this->actingAs($other)->put(route('admin.users.update', $owner), [
            'name' => 'Owner', 'email' => 'taken.over@example.test', 'role' => 'public', 'password' => 'new-pass-999', 'password_confirmation' => 'new-pass-999',
        ])->assertSessionHas('error', 'This is the permanent administrator account. Only its owner can change it.');
        $this->actingAs($other)->get(route('admin.users.edit', $owner))->assertRedirect(route('admin.users.show', $owner));

        $this->assertSame($before, $owner->fresh()->only(['email', 'password', 'role', 'deactivated_at', 'deleted_at']));
        $this->assertTrue(Hash::check(self::PASSWORD, $owner->fresh()->password));
        $this->assertSame(0, AuditLog::whereIn('action', ['admin.user_deleted', 'admin.user_deactivated', 'admin.user_updated'])->count());

        // The list offers no Delete, Deactivate or Edit for it.
        $page = $this->actingAs($other)->get(route('admin.users.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('admin.users.delete', $owner), $page);
        $this->assertStringNotContainsString('action="'.route('admin.users.destroy', $owner).'"', $page);
        $this->assertStringNotContainsString(route('admin.users.edit', $owner), $page);
        $this->assertStringContainsString(route('admin.users.edit', $other), $page);
    }

    public function test_the_owner_can_still_edit_their_own_name_and_other_admins_stay_manageable(): void
    {
        config(['accounts.protected' => ['owner@example.test']]);
        $owner = $this->admin('owner@example.test', 'Owner');
        $other = $this->admin('second.admin@example.test', 'Second Admin');

        $this->actingAs($owner)->get(route('admin.users.edit', $owner))->assertOk();
        $this->actingAs($owner)->put(route('admin.users.update', $owner), ['name' => 'Owner Renamed', 'email' => 'owner@example.test', 'role' => 'admin'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Owner Renamed', $owner->fresh()->name);

        $this->actingAs($owner)->delete(route('admin.users.destroy', $other), ['reason' => 'Second admin no longer needed.'])->assertRedirect();
        $this->assertNotNull($other->fresh()->deactivated_at);
    }

    public function test_no_account_is_protected_unless_configured(): void
    {
        $this->assertSame([], config('accounts.protected'));
        $this->assertSame([], config('accounts.protected_invalid'));
    }

    public function test_the_list_is_trimmed_lower_cased_deduplicated_and_validated(): void
    {
        $this->assertSame(
            ['valid' => ['owner@example.com', 'second.admin@example.org'], 'invalid' => ['not-an-email', 'two@@example.net']],
            ProtectedAccounts::parse(' Owner@Example.COM ,not-an-email,, owner@example.com, second.admin@example.org ,two@@example.net')
        );
        $this->assertSame(['valid' => [], 'invalid' => []], ProtectedAccounts::parse(null));
        $this->assertSame(['valid' => [], 'invalid' => []], ProtectedAccounts::parse(' , '));
    }

    public function test_the_config_file_reads_protected_accounts_from_the_environment(): void
    {
        $_SERVER['PROTECTED_ACCOUNTS'] = $_ENV['PROTECTED_ACCOUNTS'] = 'Owner@Example.com, bad-entry';

        try {
            $config = require config_path('accounts.php');
        } finally {
            unset($_SERVER['PROTECTED_ACCOUNTS'], $_ENV['PROTECTED_ACCOUNTS']);
        }

        $this->assertSame(['owner@example.com'], $config['protected']);
        $this->assertSame(['bad-entry'], $config['protected_invalid']);
    }

    public function test_deploy_check_warns_when_no_valid_protected_account_is_set(): void
    {
        config(['accounts.protected' => [], 'accounts.protected_invalid' => ['bad-entry']]);
        Artisan::call('enotary:deploy-check');
        $output = Artisan::output();
        $this->assertMatchesRegularExpression('/Protected Admin account configured\s*\|\s*WARN/', $output);
        $this->assertMatchesRegularExpression('/Protected accounts entries valid\s*\|\s*WARN/', $output);
        $this->assertStringNotContainsString('bad-entry', $output, 'entries are counted, never printed');

        config(['accounts.protected' => ['owner@example.com'], 'accounts.protected_invalid' => []]);
        Artisan::call('enotary:deploy-check');
        $output = Artisan::output();
        $this->assertMatchesRegularExpression('/Protected Admin account configured\s*\|\s*PASS/', $output);
        $this->assertMatchesRegularExpression('/Protected accounts entries valid\s*\|\s*PASS/', $output);
    }
}
