<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\FileStorageService;
use App\Services\ProductService;
use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ExternalStorageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', true, 'boolean');
        $organization = Organization::factory()->create();
        $this->admin = User::factory()->create(['organization_id' => $organization->id]);
        $this->admin->forceFill(['role' => 'admin'])->save();
        $this->actingAs($this->admin);
        config(['filesystems.uploads_disk' => null]);
        Storage::fake('public');
        Storage::fake('local');
        Storage::fake('s3');
    }

    public function test_local_defaults_and_admin_page_never_expose_credentials(): void
    {
        $this->assertSame('public', app(FileStorageService::class)->purposes()['products']);
        $this->assertSame('local', app(FileStorageService::class)->purposes()['logos']);
        config(['filesystems.uploads_disk' => 's3', 'filesystems.disks.s3.key' => 'private-access-key', 'filesystems.disks.s3.secret' => 'private-secret']);
        $response = $this->get(route('settings.storage.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Storage')->where('disks.0.name', 's3')->where('auth.canManageStorage', true));
        $response->assertDontSee('private-access-key')->assertDontSee('private-secret');
    }

    public function test_only_the_installation_admin_can_inspect_or_probe_storage(): void
    {
        $member = User::factory()->create(['organization_id' => $this->admin->organization_id]);
        $member->forceFill(['role' => 'member'])->save();
        $otherAdmin = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $otherAdmin->forceFill(['role' => 'admin'])->save();
        foreach ([$member, $otherAdmin] as $user) {
            $this->actingAs($user)->get(route('settings.storage.index'))->assertForbidden();
            $this->postJson(route('settings.storage.check'), ['disk' => 'local'])->assertForbidden();
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_guest_is_redirected_and_arbitrary_disks_cannot_be_probed(): void
    {
        $this->postJson(route('settings.storage.check'), ['disk' => 'https://untrusted.example'])->assertUnprocessable();
        auth()->logout();
        $this->get(route('settings.storage.index'))->assertRedirect(route('login'));
    }

    public function test_connection_success_requires_write_read_and_delete_and_preserves_other_files(): void
    {
        config(['filesystems.uploads_disk' => 's3']);
        Storage::disk('s3')->put('existing.txt', 'keep');
        $this->postJson(route('settings.storage.check'), ['disk' => 's3'])
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('cleanup_failed', false);
        $this->assertSame(['existing.txt'], Storage::disk('s3')->allFiles());
        $this->get(route('settings.storage.index'))->assertInertia(fn (Assert $page) => $page->where('disks.0.last_check.success', true));
        config(['filesystems.disks.s3.bucket' => 'a-different-bucket']);
        $this->get(route('settings.storage.index'))->assertInertia(fn (Assert $page) => $page->where('disks.0.last_check', null));
    }

    public function test_bad_configuration_returns_a_safe_failure(): void
    {
        config(['filesystems.uploads_disk' => 'missing']);
        $this->postJson(route('settings.storage.check'), ['disk' => 'missing'])
            ->assertOk()->assertJsonPath('success', false)->assertJsonPath('failed_step', 'connect');
    }

    public function test_failed_writes_do_not_return_a_successful_connection(): void
    {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::set('local', $disk);
        $this->postJson(route('settings.storage.check'), ['disk' => 'local'])
            ->assertOk()->assertJsonPath('success', false)->assertJsonPath('failed_step', 'write');
    }

    public function test_read_failure_is_not_reported_as_connected_and_attempts_cleanup(): void
    {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(true);
        $disk->shouldReceive('get')->once()->andThrow(new \RuntimeException('SECRET SIGNED URL'));
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::set('local', $disk);
        $this->postJson(route('settings.storage.check'), ['disk' => 'local'])
            ->assertOk()->assertJsonPath('success', false)->assertJsonPath('failed_step', 'read')->assertDontSee('SECRET');
    }

    public function test_delete_failure_is_reported_including_cleanup_failure(): void
    {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $payload = null;
        $disk->shouldReceive('put')->once()->andReturnUsing(function ($path, $contents) use (&$payload) {
            $payload = $contents;

            return true;
        });
        $disk->shouldReceive('get')->once()->andReturnUsing(function () use (&$payload) {
            return $payload;
        });
        $disk->shouldReceive('delete')->twice()->andReturn(false);
        Storage::set('local', $disk);
        $this->postJson(route('settings.storage.check'), ['disk' => 'local'])
            ->assertOk()->assertJsonPath('success', false)->assertJsonPath('failed_step', 'delete')->assertJsonPath('cleanup_failed', true);
    }

    public function test_real_s3_adapter_checks_commands_with_a_private_object_without_network(): void
    {
        Storage::forgetDisk('adapter-test');
        config(['filesystems.disks.adapter-test' => [
            'driver' => 's3', 'key' => 'test', 'secret' => 'test',
            'region' => 'us-east-1', 'bucket' => 'private-bucket', 'root' => 'prefix',
            'endpoint' => 'https://s3.example.test', 'use_path_style_endpoint' => true,
            'omit_acl' => true,
            'throw' => true,
        ]]);
        $disk = Storage::disk('adapter-test');
        $commands = [];
        $payload = '';
        $disk->getClient()->getHandlerList()->setHandler(function (CommandInterface $command) use (&$commands, &$payload) {
            $commands[] = $command->getName();
            $this->assertSame('private-bucket', $command['Bucket']);
            $this->assertStringStartsWith('prefix/.inventoros/connection-checks/', $command['Key']);
            if ($command->getName() === 'PutObject') {
                $this->assertFalse(isset($command['ACL']), 'Private buckets must not require object ACL support.');
                $payload = (string) Utils::streamFor($command['Body']);
            }
            if ($command->getName() === 'HeadObject') {
                return Create::rejectionFor(new S3Exception('not found', $command, ['code' => 'NotFound', 'response' => new Response(404)]));
            }

            return Create::promiseFor(new Result(['Body' => Utils::streamFor($payload)]));
        });
        $result = app(FileStorageService::class)->check('adapter-test');
        $this->assertTrue($result['success'], json_encode([$result, $commands]));
        $this->assertSame(['PutObject', 'GetObject', 'DeleteObject', 'HeadObject'], $commands);
    }

    public function test_product_images_keep_their_disks_when_mixing_legacy_and_external_files(): void
    {
        $service = app(ProductService::class);
        $product = $service->create($this->productData());
        $oldPath = $product->images[0];
        Storage::disk('public')->assertExists($oldPath);
        config(['filesystems.uploads_disk' => 's3']);
        $service->update($product, ['images' => [
            ['url' => '/storage/'.$oldPath, 'preview' => '/storage/'.$oldPath],
            ['preview' => self::PNG],
        ]]);
        $product->refresh();
        $newPath = $product->images[1];
        $this->assertSame('public', $product->image_disks[$oldPath]);
        $this->assertSame('s3', $product->image_disks[$newPath]);
        Storage::disk('s3')->assertExists($newPath);
        config(['filesystems.uploads_disk' => null]);
        foreach ($product->image_urls as $url) {
            $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        }
        $service->update($product, ['images' => [['url' => $product->image_urls[1], 'preview' => $product->image_urls[1]]]]);
        Storage::disk('public')->assertMissing($oldPath);
        $service->update($product, ['images' => []]);
        Storage::disk('s3')->assertMissing($newPath);
        $this->assertNull($product->fresh()->thumbnail);
    }

    public function test_legacy_image_reads_and_cross_tenant_image_requests(): void
    {
        $product = Product::factory()->create(['organization_id' => $this->admin->organization_id, 'images' => ['products/legacy.png']]);
        Storage::disk('public')->put('products/legacy.png', base64_decode(explode(',', self::PNG)[1]));
        $this->get($product->image_urls[0])->assertOk();
        $this->get(route('products.images.show', [$product, 100]))->assertNotFound();
        $other = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $other->forceFill(['role' => 'admin'])->save();
        $this->actingAs($other)->get($product->image_urls[0])->assertNotFound();
    }

    public function test_api_clients_can_read_and_retain_private_images_without_public_bucket_urls(): void
    {
        config(['filesystems.uploads_disk' => 's3']);
        $product = app(ProductService::class)->create($this->productData());
        Sanctum::actingAs($this->admin, ['*']);
        $url = $this->getJson('/api/v1/products/'.$product->id)->assertOk()->json('data.image_urls.0');
        $this->assertStringStartsWith('/api/v1/products/', $url);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->patchJson('/api/v1/products/'.$product->id, ['images' => [['url' => $url]]])->assertOk();
        Storage::disk('s3')->assertExists($product->fresh()->images[0]);
        $other = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
        $other->forceFill(['role' => 'admin'])->save();
        Sanctum::actingAs($other, ['*']);
        $this->get($url)->assertNotFound();
    }

    public function test_a_foreign_image_reference_cannot_be_attached_to_a_product(): void
    {
        $product = app(ProductService::class)->create($this->productData());
        $this->expectException(ValidationException::class);
        app(ProductService::class)->update($product, ['images' => [['url' => '/storage/products/someone-elses.png']]]);
    }

    public function test_failed_image_upload_leaves_the_product_and_existing_images_unchanged(): void
    {
        $product = app(ProductService::class)->create($this->productData());
        $oldPath = $product->images[0];
        config(['filesystems.uploads_disk' => 's3']);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::set('s3', $disk);
        try {
            app(ProductService::class)->update($product, ['images' => [['preview' => self::PNG]]]);
            $this->fail('Expected an upload failure.');
        } catch (\RuntimeException) {
            $this->assertSame([$oldPath], $product->fresh()->images);
            Storage::disk('public')->assertExists($oldPath);
        }
    }

    public function test_failed_database_save_removes_new_uploads_and_keeps_previous_images(): void
    {
        $service = app(ProductService::class);
        $product = $service->create($this->productData());
        $oldPath = $product->images[0];
        Product::factory()->create(['organization_id' => $this->admin->organization_id, 'sku' => 'TAKEN']);
        config(['filesystems.uploads_disk' => 's3']);
        try {
            $service->update($product, ['sku' => 'TAKEN', 'images' => [['preview' => self::PNG]]]);
            $this->fail('Expected a duplicate SKU error.');
        } catch (QueryException) {
            $this->assertSame([$oldPath], $product->fresh()->images);
            Storage::disk('public')->assertExists($oldPath);
            $this->assertSame([], Storage::disk('s3')->allFiles());
        }
    }

    public function test_removing_an_image_does_not_delete_a_duplicated_products_file(): void
    {
        $product = app(ProductService::class)->create($this->productData());
        $duplicate = $product->replicate();
        $duplicate->sku = 'COPY';
        $duplicate->save();
        app(ProductService::class)->update($product, ['images' => []]);
        Storage::disk('public')->assertExists($duplicate->images[0]);
    }

    public function test_replacing_an_external_logo_uses_its_original_disk_after_reconfiguration(): void
    {
        config(['filesystems.uploads_disk' => 's3']);
        $this->post(route('settings.organization.logo.store'), ['logo' => UploadedFile::fake()->image('logo.png')])->assertSessionHasNoErrors();
        $organization = $this->admin->organization->fresh();
        $oldPath = $organization->logo_path;
        $this->assertSame('s3', $organization->logo_disk);
        config(['filesystems.uploads_disk' => null]);
        $this->get($organization->logo_url)->assertOk();
        $this->post(route('settings.organization.logo.store'), ['logo' => UploadedFile::fake()->image('new.png')])->assertSessionHasNoErrors();
        Storage::disk('s3')->assertMissing($oldPath);
        $this->assertSame('local', $organization->fresh()->logo_disk);
    }

    private function productData(): array
    {
        return ['organization_id' => $this->admin->organization_id, 'sku' => 'IMAGE', 'name' => 'Image product',
            'price' => 10, 'stock' => 0, 'min_stock' => 0, 'images' => [['preview' => self::PNG]]];
    }
}
