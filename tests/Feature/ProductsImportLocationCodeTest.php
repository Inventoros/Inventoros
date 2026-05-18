<?php

namespace Tests\Feature;

use App\Imports\ProductsImport;
use App\Models\Auth\Organization;
use App\Models\Inventory\ProductLocation;
use App\Models\System\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ProductsImportLocationCodeTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        $this->org = Organization::create(['name' => 'Imp', 'email' => 'imp@test.com']);
    }

    public function test_import_generates_distinct_codes_for_locations_with_same_prefix(): void
    {
        $import = new ProductsImport($this->org->id);

        $import->collection(new Collection([
            collect([
                'sku' => 'P1', 'name' => 'P1', 'price' => 1, 'stock' => 1,
                'location' => 'Toronto Main',
            ]),
            collect([
                'sku' => 'P2', 'name' => 'P2', 'price' => 1, 'stock' => 1,
                'location' => 'Toronto Backup',
            ]),
        ]));

        $first = ProductLocation::where('organization_id', $this->org->id)
            ->where('name', 'Toronto Main')
            ->first();
        $second = ProductLocation::where('organization_id', $this->org->id)
            ->where('name', 'Toronto Backup')
            ->first();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame('TOR', $first->code);
        // Second row's derived prefix collides with TOR; helper must
        // produce a distinct code rather than re-using TOR.
        $this->assertNotSame('TOR', $second->code);
        $this->assertSame('TOR-2', $second->code);
    }

    public function test_import_skips_pre_existing_code_when_external_one_already_holds_it(): void
    {
        // Operator pre-created a location named "Other" but with code TOR.
        ProductLocation::create([
            'organization_id' => $this->org->id,
            'name' => 'Other',
            'code' => 'TOR',
            'is_active' => true,
        ]);

        $import = new ProductsImport($this->org->id);

        $import->collection(new Collection([
            collect([
                'sku' => 'P1', 'name' => 'P1', 'price' => 1, 'stock' => 1,
                'location' => 'Toronto Main',
            ]),
        ]));

        $imported = ProductLocation::where('organization_id', $this->org->id)
            ->where('name', 'Toronto Main')
            ->first();

        $this->assertNotNull($imported);
        $this->assertNotSame('TOR', $imported->code);
        $this->assertSame('TOR-2', $imported->code);
    }
}
