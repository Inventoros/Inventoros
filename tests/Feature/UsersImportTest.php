<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exports\UsersExport;
use App\Imports\UsersImport;
use App\Models\Auth\Organization;
use App\Models\Role;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Notifications\AccountInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * User CSV import: name, email, base role, custom roles. Passwords are never
 * part of the file; new users get a random password and a set-password link.
 */
final class UsersImportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    private Role $picker;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        SystemSetting::set('installed', true, 'boolean');
        $this->org = Organization::create(['name' => 'Org', 'email' => 'o@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@org.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
        $this->picker = Role::create([
            'name' => 'Picker', 'slug' => 'picker', 'organization_id' => $this->org->id,
            'permissions' => ['view_products'],
        ]);
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function csv(array $lines, string $header = 'name,email,role,roles'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('users.csv', $header."\n".implode("\n", $lines)."\n");
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function import(array $lines, ?User $as = null, bool $sendInvites = true, string $header = 'name,email,role,roles'): UsersImport
    {
        $import = new UsersImport($as ?? $this->admin, $sendInvites);
        Excel::import($import, $this->csv($lines, $header));

        return $import;
    }

    private function delegatedManager(array $permissions): User
    {
        $manager = User::create([
            'name' => 'Manager', 'email' => 'manager@org.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);
        $role = Role::create([
            'name' => 'User manager', 'slug' => 'user-manager', 'organization_id' => $this->org->id,
            'permissions' => $permissions,
        ]);
        $manager->roles()->attach($role->id);

        return $manager->fresh();
    }

    public function test_it_creates_users_with_roles_and_emails_a_set_password_link(): void
    {
        $import = $this->import([
            'Pat Picker,pat@org.com,member,Picker',
            'Morgan Manager,morgan@org.com,manager,',
        ]);

        $stats = $import->getStats();
        $this->assertSame([], $stats['errors']);
        $this->assertSame(2, $stats['imported']);

        $pat = User::where('email', 'pat@org.com')->sole();
        $this->assertSame($this->org->id, $pat->organization_id);
        $this->assertSame('member', $pat->role);
        $this->assertSame(['Picker'], $pat->roles->pluck('name')->all());
        $this->assertSame('manager', User::where('email', 'morgan@org.com')->sole()->role);

        // A random password is set; nothing guessable.
        $this->assertNotEmpty($pat->password);
        $this->assertFalse(Hash::check('', $pat->password));

        Notification::assertSentTo($pat, AccountInvitation::class, function (AccountInvitation $notification) use ($pat) {
            return Password::broker()->tokenExists($pat, $notification->token);
        });
    }

    public function test_invites_can_be_left_pending(): void
    {
        $this->import(['Pat Picker,pat@org.com,member,'], sendInvites: false);

        $this->assertTrue(User::where('email', 'pat@org.com')->exists());
        Notification::assertNothingSent();
    }

    public function test_duplicate_emails_are_skipped_with_a_warning(): void
    {
        $other = Organization::create(['name' => 'Other', 'email' => 'x@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        User::create([
            'name' => 'Elsewhere', 'email' => 'taken@else.com', 'password' => bcrypt('x'),
            'organization_id' => $other->id, 'role' => 'member',
        ]);

        $import = $this->import([
            'Taken,TAKEN@else.com,member,',
            'Fresh,fresh@org.com,member,',
            'Fresh again,fresh@org.com,member,',
        ]);

        $stats = $import->getStats();
        $this->assertSame(1, $stats['imported']);
        $this->assertSame(2, $stats['skipped']);
        $this->assertSame([2, 4], array_column($stats['warnings'], 'row'));
        $this->assertSame($other->id, User::where('email', 'taken@else.com')->sole()->organization_id);
        $this->assertSame('Fresh', User::where('email', 'fresh@org.com')->sole()->name);
    }

    public function test_a_non_admin_importer_cannot_create_admins_or_managers(): void
    {
        $manager = $this->delegatedManager(['import_data', 'create_users', 'view_products']);

        $import = $this->import([
            'Sneaky Admin,sneaky@org.com,admin,',
            'Sneaky Manager,sneaky2@org.com,manager,',
            'Fine Member,fine@org.com,member,Picker',
        ], as: $manager);

        $stats = $import->getStats();
        $this->assertSame(1, $stats['imported']);
        $this->assertSame([2, 3], array_column($stats['errors'], 'row'));
        $this->assertStringContainsString('permission', $stats['errors'][0]['errors'][0]);
        $this->assertFalse(User::where('email', 'sneaky@org.com')->exists());
        $this->assertFalse(User::where('email', 'sneaky2@org.com')->exists());
        $this->assertTrue(User::where('email', 'fine@org.com')->exists());
    }

    public function test_a_non_admin_importer_cannot_assign_the_administrator_role_or_wider_permissions(): void
    {
        Role::create([
            'name' => 'Administrator', 'slug' => 'system-administrator', 'is_system' => true,
            'permissions' => ['view_users'],
        ]);
        Role::create([
            'name' => 'Deleter', 'slug' => 'deleter', 'organization_id' => $this->org->id,
            'permissions' => ['delete_products'],
        ]);
        $manager = $this->delegatedManager(['import_data', 'create_users', 'view_products', 'view_users']);

        $import = $this->import([
            'Via Role,via-role@org.com,member,Administrator',
            'Wider,wider@org.com,member,Deleter',
        ], as: $manager);

        $this->assertSame(0, $import->getStats()['imported']);
        $this->assertCount(2, $import->getStats()['errors']);
        $this->assertFalse(User::where('email', 'via-role@org.com')->exists());
        $this->assertFalse(User::where('email', 'wider@org.com')->exists());
    }

    public function test_roles_from_another_organization_are_unknown(): void
    {
        $other = Organization::create(['name' => 'Other', 'email' => 'x@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        Role::create(['name' => 'Foreign', 'slug' => 'foreign', 'organization_id' => $other->id, 'permissions' => []]);

        $import = $this->import(['Pat,pat@org.com,member,Foreign']);

        $this->assertStringContainsString("Unknown role 'Foreign'", $import->getStats()['errors'][0]['errors'][0]);
        $this->assertFalse(User::where('email', 'pat@org.com')->exists());
    }

    public function test_row_validation_errors_are_reported(): void
    {
        $import = $this->import([
            ',nameless@org.com,member,',
            'Bad Email,not-an-email,member,',
            'Bad Role,bad-role@org.com,owner,',
        ]);

        $this->assertSame([2, 3, 4], array_column($import->getStats()['errors'], 'row'));
        $this->assertSame(1, User::count());
    }

    public function test_a_password_column_is_ignored_and_reported(): void
    {
        $import = $this->import(['Pat,pat@org.com,member,,hunter2'], header: 'name,email,role,roles,password');

        $pat = User::where('email', 'pat@org.com')->sole();
        $this->assertFalse(Hash::check('hunter2', $pat->password));
        $this->assertStringContainsString('password', strtolower($import->getStats()['warnings'][0]['warnings'][0]));
    }

    public function test_the_export_carries_the_base_role_and_never_a_password(): void
    {
        $export = new UsersExport($this->org->id);
        $headings = $export->headings();

        $this->assertContains('Role', $headings);
        foreach ($headings as $heading) {
            $this->assertStringNotContainsStringIgnoringCase('password', $heading);
        }

        $row = array_combine($headings, $export->map($export->query()->sole()));
        $this->assertSame('admin', $row['Role']);
        $this->assertNotContains($this->admin->password, $row);
    }

    public function test_the_endpoint_requires_import_data_and_create_users(): void
    {
        $importOnly = $this->delegatedManager(['import_data']);

        $this->actingAs($importOnly)->post(route('import-export.import-users'), [
            'file' => $this->csv(['Pat,pat@org.com,member,']),
        ])->assertForbidden();

        $this->actingAs($this->admin)->post(route('import-export.import-users'), [
            'file' => $this->csv(['Pat,pat@org.com,member,']),
            'send_invites' => '1',
        ])->assertRedirect(route('import-export.index'));

        $this->assertIsString(session('success'));
        $this->assertTrue(User::where('email', 'pat@org.com')->exists());
    }

    public function test_the_template_has_no_password_column(): void
    {
        $csv = $this->actingAs($this->admin)->get(route('import-export.download-user-template'))->streamedContent();
        $header = str_getcsv(strtok($csv, "\n"), escape: '');

        $this->assertSame(['name', 'email', 'role', 'roles'], $header);
    }
}
