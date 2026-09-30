<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Imports\ProductsImport;
use App\Imports\UsersImport;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * A row that fails in the database is reported against that row and the
 * rest of the file is still imported.
 *
 * Laravel Excel runs an import inside a database transaction. On PostgreSQL
 * a failed statement aborts the whole transaction: every later statement
 * fails with "current transaction is aborted" and the final commit rolls
 * back the rows reported as imported. Each row therefore runs in its own
 * (nested) transaction, whose savepoint is rolled back on failure, leaving
 * the surrounding transaction usable.
 */
class ImportRowDatabaseErrorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->org = Organization::create(['name' => 'Org', 'email' => 'o@org.com', 'currency' => 'USD', 'timezone' => 'UTC']);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@org.com', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);
    }

    public function test_a_product_row_the_database_rejects_does_not_sink_the_others(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite stores an out-of-range decimal instead of rejecting it; runs on the MySQL and PostgreSQL CI legs.');
        }

        // Row 3's price overflows decimal(10,2) and fails in the database,
        // after passing validation.
        $csv = "name,sku,price,stock\nFirst,ROW-1,10,1\nBroken,ROW-2,99999999999,1\nThird,ROW-3,12,1\n";

        $import = new ProductsImport($this->org->id);
        Excel::import($import, UploadedFile::fake()->createWithContent('products.csv', $csv));

        $stats = $import->getStats();
        $this->assertSame(2, $stats['imported']);
        $this->assertCount(1, $stats['errors']);
        $this->assertSame(3, $stats['errors'][0]['row']);

        $this->assertSame(['ROW-1', 'ROW-3'], Product::withoutGlobalScopes()->orderBy('sku')->pluck('sku')->all());
    }

    public function test_a_user_row_the_database_rejects_does_not_sink_the_others(): void
    {
        // The second user's insert fails in the database.
        User::creating(function (User $user) {
            if ($user->email === 'broken@org.com') {
                DB::select('select * from import_row_missing_table');
            }
        });

        $csv = "name,email,role\nFirst,first@org.com,member\nBroken,broken@org.com,member\nThird,third@org.com,member\n";

        $import = new UsersImport($this->admin, sendInvites: false);
        Excel::import($import, UploadedFile::fake()->createWithContent('users.csv', $csv));

        $stats = $import->getStats();
        $this->assertSame(2, $stats['imported']);
        $this->assertCount(1, $stats['errors']);
        $this->assertSame(3, $stats['errors'][0]['row']);
        $this->assertStringContainsString('could not be saved', $stats['errors'][0]['errors'][0]);

        $this->assertSame(
            ['admin@org.com', 'first@org.com', 'third@org.com'],
            User::orderBy('email')->pluck('email')->all(),
        );
    }
}
