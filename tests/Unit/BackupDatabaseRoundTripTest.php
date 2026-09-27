<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Update\BackupService;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use ZipArchive;

/**
 * Real backup/restore round trips against a real database connection.
 *
 * On the SQLite CI leg these run against a scratch SQLite file. On the MySQL
 * and PostgreSQL legs the pure-PHP dump runs against the real server, limited
 * to two probe tables so the rest of the test database is untouched.
 */
final class BackupDatabaseRoundTripTest extends TestCase
{
    private const PROBE_TABLES = ['backup_probe_parents', 'backup_probe_children'];

    private string $root;

    private string $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('app/testing/roundtrip_'.uniqid());
        File::makeDirectory($this->root.'/backups', 0755, true, true);

        if (config('database.connections.'.config('database.default').'.driver') === 'sqlite') {
            // A file-backed database so the snapshot restore has a file to replace.
            $file = $this->root.'/probe.sqlite';
            touch($file);
            config(['database.connections.backup_probe' => [
                'driver' => 'sqlite',
                'database' => $file,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]]);
            config(['database.default' => 'backup_probe']);
        }

        $this->connection = config('database.default');
        $this->createProbeTables();
    }

    protected function tearDown(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('backup_probe_children');
        $schema->dropIfExists('backup_probe_parents');
        DB::purge('backup_probe');
        File::deleteDirectory(storage_path('app/testing'));

        parent::tearDown();
    }

    private function createProbeTables(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('backup_probe_children');
        $schema->dropIfExists('backup_probe_parents');

        $schema->create('backup_probe_parents', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->decimal('price', 10, 2)->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->timestamps();
            $table->index('name');
        });

        $schema->create('backup_probe_children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_probe_parent_id')->constrained('backup_probe_parents')->cascadeOnDelete();
            $table->string('label');
        });

        $db = DB::connection($this->connection);
        $db->table('backup_probe_parents')->insert([
            ['id' => 1, 'name' => "O'Brien; DROP TABLE x; --", 'notes' => "line one\nline two\r\n-- not a comment", 'active' => true, 'price' => '12.50', 'parent_id' => null, 'created_at' => '2026-01-02 03:04:05', 'updated_at' => null],
            ['id' => 2, 'name' => 'Ünïcödé ✓ 倉庫', 'notes' => null, 'active' => false, 'price' => null, 'parent_id' => 1, 'created_at' => null, 'updated_at' => null],
            ['id' => 3, 'name' => 'back\\slash', 'notes' => '', 'active' => true, 'price' => '0.00', 'parent_id' => 2, 'created_at' => null, 'updated_at' => null],
        ]);

        // Enough child rows to span several dump chunks.
        $rows = [];
        for ($i = 1; $i <= 1203; $i++) {
            $rows[] = ['id' => $i, 'backup_probe_parent_id' => ($i % 3) + 1, 'label' => "child {$i}"];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            $db->table('backup_probe_children')->insert($chunk);
        }
    }

    private function service(): BackupService
    {
        return new class($this->root.'/backups', $this->connection) extends BackupService
        {
            public bool $forcePhp = false;

            protected function basePath(): string
            {
                return $this->getBackupPath();
            }

            protected function itemsToBackup(): array
            {
                return [];
            }

            protected function canExec(): bool
            {
                // Only the PHP dump / SQLite snapshot paths are under test here.
                return false;
            }

            protected function sqliteSnapshot(Connection $connection, string $outputPath): void
            {
                if ($this->forcePhp) {
                    throw new \RuntimeException('snapshot disabled for this test');
                }

                parent::sqliteSnapshot($connection, $outputPath);
            }

            protected function tableNames(Connection $connection): array
            {
                return array_values(array_filter(
                    parent::tableNames($connection),
                    fn (string $table) => str_starts_with($table, 'backup_probe_')
                ));
            }
        };
    }

    private function snapshot(): array
    {
        $db = DB::connection($this->connection);

        return [
            'parents' => $db->table('backup_probe_parents')->orderBy('id')->get()
                ->map(fn ($r) => [
                    (int) $r->id, $r->name, $r->notes, (bool) $r->active,
                    $r->price === null ? null : number_format((float) $r->price, 2),
                    $r->parent_id === null ? null : (int) $r->parent_id,
                    $r->created_at === null ? null : substr((string) $r->created_at, 0, 19),
                ])->all(),
            'children' => $db->table('backup_probe_children')->count(),
            'child_sum' => (int) $db->table('backup_probe_children')->sum('backup_probe_parent_id'),
        ];
    }

    private function vandalise(): void
    {
        $db = DB::connection($this->connection);
        $db->table('backup_probe_children')->where('id', '>', 10)->delete();
        $db->table('backup_probe_parents')->where('id', 1)->update(['name' => 'changed']);
        $db->table('backup_probe_parents')->insert(['id' => 99, 'name' => 'added after backup', 'active' => true]);
    }

    private function extract(string $zipPath): string
    {
        $dir = $this->root.'/extract_'.uniqid();
        File::makeDirectory($dir, 0755, true, true);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $zip->extractTo($dir);
        $zip->close();

        return $dir;
    }

    public function test_php_dump_round_trip_restores_rows_exactly(): void
    {
        $before = $this->snapshot();

        $service = $this->service();
        $service->forcePhp = true;
        $zip = $service->createBackup();

        $this->assertSame('php-dump', $service->lastDatabaseBackupMethod());

        $this->vandalise();
        $this->assertNotSame($before, $this->snapshot());

        $this->assertSame('php-dump', $service->restoreDatabase($this->extract($zip)));

        DB::purge($this->connection);
        $this->assertSame($before, $this->snapshot());

        // Auto-increment counters continue after the restored ids.
        $newId = DB::connection($this->connection)->table('backup_probe_parents')->insertGetId(['name' => 'next', 'active' => true]);
        $this->assertGreaterThan(3, $newId);
    }

    public function test_php_dump_restore_is_all_or_nothing(): void
    {
        $service = $this->service();
        $service->forcePhp = true;
        $extract = $this->extract($service->createBackup());

        // Corrupt the dump after the first statement block.
        $sql = File::get($extract.'/database.sql');
        File::put($extract.'/database.sql', preg_replace('/INSERT INTO/', 'INSERT INTO nope_missing_table', $sql, 1, $count));
        $this->assertSame(1, $count);

        $this->vandalise();
        $vandalised = $this->snapshot();

        try {
            $service->restoreDatabase($extract);
            $this->fail('A broken dump must fail the restore.');
        } catch (\Throwable) {
            // expected
        }

        if (DB::connection($this->connection)->getDriverName() !== 'mysql') {
            // MySQL DDL commits implicitly, so only transactional-DDL drivers
            // can promise the database is untouched after a failed restore.
            DB::purge($this->connection);
            $this->assertSame($vandalised, $this->snapshot());
        }
    }

    public function test_sqlite_snapshot_round_trip(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite snapshot applies to the SQLite driver only.');
        }

        $before = $this->snapshot();

        $service = $this->service();
        $zip = $service->createBackup();

        $this->assertSame('sqlite-snapshot', $service->lastDatabaseBackupMethod());

        $extract = $this->extract($zip);
        $this->assertFileExists($extract.'/database.sqlite');

        $this->vandalise();

        $this->assertSame('sqlite-snapshot', $service->restoreDatabase($extract));

        $this->assertSame($before, $this->snapshot());
    }
}
