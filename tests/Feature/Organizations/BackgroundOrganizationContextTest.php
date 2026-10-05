<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Jobs\ProcessOrderImportJob;
use App\Mail\ScheduledReportEmail;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Order\Order;
use App\Models\ReportSchedule;
use App\Models\SavedReport;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Services\Organizations\ActiveOrganization;
use App\Services\Organizations\OrganizationMembershipService;
use App\Services\Reports\ScheduledReportRunner;
use App\Support\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Organizations\Concerns\BuildsOrganizations;
use Tests\TestCase;

/**
 * Work that runs outside the user's request (queued jobs, scheduled reports,
 * plugin code) works in the organization it was started in, checked against
 * the user's memberships when it runs, and runAs() confines scoped queries
 * to one organization without leaking into the caller.
 */
final class BackgroundOrganizationContextTest extends TestCase
{
    use BuildsOrganizations, RefreshDatabase;

    private const ORDER_HEADER = 'external_reference,order_date,status,customer_name,customer_email,product_sku,variant_sku,quantity,unit_price,order_tax,order_shipping,notes';

    private Organization $alpha;

    private Organization $beta;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();

        $this->alpha = $this->organization('Alpha');
        $this->beta = $this->organization('Beta');
        $this->user = $this->homeUser($this->alpha, 'admin');
        $this->homeUser($this->beta, 'admin', 'Owner');
        $this->product($this->alpha, 'ALPHA-1');
        $this->product($this->beta, 'BETA-1');
    }

    private function organizations(): ActiveOrganization
    {
        return app(ActiveOrganization::class);
    }

    public function test_run_as_confines_scoped_queries_and_new_rows_to_the_organization(): void
    {
        $this->addMember($this->beta, $this->user, 'admin');
        $this->actingAs($this->user);

        $inside = $this->organizations()->runAs($this->user, $this->beta->id, 'view_products', function (User $member) {
            $skus = Product::pluck('sku')->all();
            $made = Product::create(['sku' => 'INSIDE-1', 'name' => 'Made inside', 'price' => 1, 'currency' => 'USD', 'stock' => 0, 'min_stock' => 0]);

            return [$member->organization_id, $skus, $made->organization_id];
        });

        $this->assertSame([$this->beta->id, ['BETA-1'], $this->beta->id], $inside);

        // Nothing leaks out: the caller is back in its own organization.
        $this->assertSame($this->alpha->id, auth()->user()->organization_id);
        $this->assertSame(['ALPHA-1'], Product::pluck('sku')->all());
    }

    public function test_work_inside_run_as_is_logged_and_configured_in_that_organization(): void
    {
        $this->addMember($this->beta, $this->user, 'admin');
        \App\Models\Setting::create(['organization_id' => $this->alpha->id, 'key' => 'feature.flag', 'value' => 'alpha', 'encrypted' => false]);
        \App\Models\Setting::create(['organization_id' => $this->beta->id, 'key' => 'feature.flag', 'value' => 'beta', 'encrypted' => false]);
        $this->actingAs($this->user);

        [$setting, $licensed] = $this->organizations()->runAs($this->user, $this->beta->id, [], function () {
            Product::where('sku', 'BETA-1')->first()->update(['name' => 'Renamed in Beta']);
            \App\Services\SettingsService::set('written.inside', 'yes');

            return [
                \App\Services\SettingsService::get('feature.flag'),
                (new \ReflectionMethod(\App\Services\Marketplace\PluginLicenceService::class, 'organization'))
                    ->invoke(app(\App\Services\Marketplace\PluginLicenceService::class), null)?->id,
            ];
        });

        $this->assertSame('beta', $setting);
        $this->assertSame($this->beta->id, $licensed);
        $this->assertDatabaseHas('settings', ['organization_id' => $this->beta->id, 'key' => 'written.inside']);
        $this->assertDatabaseMissing('settings', ['organization_id' => $this->alpha->id, 'key' => 'written.inside']);

        $log = \App\Models\ActivityLog::where('subject_type', Product::class)->where('action', 'updated')->sole();
        $this->assertSame($this->beta->id, $log->organization_id);
    }

    public function test_changes_to_a_user_account_are_logged_in_its_home_organization(): void
    {
        $this->addMember($this->beta, $this->user, 'admin');
        $this->actingAs($this->user);
        $this->organizations()->activate(auth()->user(), $this->beta->id);

        auth()->user()->update(['name' => 'Renamed']);

        $log = \App\Models\ActivityLog::where('subject_type', User::class)->where('subject_id', $this->user->id)->where('action', 'updated')->sole();
        $this->assertSame($this->alpha->id, $log->organization_id);
    }

    public function test_an_approval_decision_reaches_the_requester_in_the_request_organization(): void
    {
        $this->addMember($this->beta, $this->user, 'admin');
        $approver = User::where('organization_id', $this->beta->id)->firstOrFail();
        $requester = $this->organizations()->userIn($this->user, $this->beta->id);
        $product = Product::withoutGlobalScope(OrganizationScope::class)->where('sku', 'BETA-1')->firstOrFail();

        $request = app(\App\Services\ApprovalService::class)->requestStockAdjustment($requester, $product, null, -2, 'damage', 'Dropped');
        $this->actingAs($approver);
        app(\App\Services\ApprovalService::class)->approve($approver, 'stock_adjustment', $request->id);

        $notification = \App\Models\Notification::where('user_id', $this->user->id)->where('type', 'approval_approved')->sole();
        $this->assertSame($this->beta->id, $notification->organization_id);
    }

    public function test_run_as_restores_the_scope_when_the_callback_throws(): void
    {
        $this->addMember($this->beta, $this->user, 'admin');
        $this->actingAs($this->user);

        try {
            $this->organizations()->runAs($this->user, $this->beta->id, [], fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(['ALPHA-1'], Product::pluck('sku')->all());
    }

    public function test_run_as_scopes_queued_work_that_has_no_signed_in_user(): void
    {
        $this->addMember($this->beta, $this->user, 'admin');

        // Unscoped outside: a job sees every organization unless it says which.
        $this->assertSame(2, Product::count());

        $skus = $this->organizations()->runAs($this->user, $this->beta->id, [], fn () => Product::pluck('sku')->all());

        $this->assertSame(['BETA-1'], $skus);
        $this->assertSame(2, Product::count());
    }

    public function test_run_as_refuses_non_members_and_missing_permissions(): void
    {
        $gamma = $this->organization('Gamma');
        $this->addMember($this->beta, $this->user, 'member');
        $ran = false;

        foreach ([
            fn () => $this->organizations()->runAs($this->user, $gamma->id, [], function () use (&$ran) { $ran = true; }),
            fn () => $this->organizations()->runAs($this->user, $this->beta->id, 'delete_products', function () use (&$ran) { $ran = true; }),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected an authorization error.');
            } catch (AuthorizationException) {
                // expected
            }
        }

        $this->assertFalse($ran);
    }

    public function test_the_context_override_is_per_request_and_job(): void
    {
        $context = app(OrganizationContext::class);
        $context->run($this->beta->id, function (): void {
            $this->app->forgetScopedInstances();
            $this->assertNotSame($this->beta->id, app(OrganizationContext::class)->id());
        });

        $this->assertFalse(app(OrganizationContext::class)->isScoped());
    }

    public function test_a_queued_order_import_runs_in_the_organization_it_was_started_in(): void
    {
        $this->addMember($this->beta, $this->user, 'admin');
        Storage::fake('local');
        Storage::disk('local')->put('imports/orders.csv', self::ORDER_HEADER."\nJ-1,2026-01-05,pending,Acme,,BETA-1,,1,10,,,\n");

        (new ProcessOrderImportJob($this->beta->id, $this->user->id, 'local', 'imports/orders.csv', false))->handle();

        $order = Order::withoutGlobalScope(OrganizationScope::class)->where('external_reference', 'J-1')->sole();
        $this->assertSame($this->beta->id, $order->organization_id);
    }

    public function test_a_queued_order_import_fails_once_the_membership_is_withdrawn(): void
    {
        $this->addMember($this->beta, $this->user, 'admin');
        Storage::fake('local');
        Storage::disk('local')->put('imports/orders.csv', self::ORDER_HEADER."\nJ-2,2026-01-05,pending,Acme,,BETA-1,,1,10,,,\n");
        $job = new ProcessOrderImportJob($this->beta->id, $this->user->id, 'local', 'imports/orders.csv', false);

        app(OrganizationMembershipService::class)->remove($this->beta, $this->user);

        try {
            $job->handle();
            $this->fail('The import must not run.');
        } catch (ModelNotFoundException) {
            // expected
        }

        $this->assertFalse(Order::withoutGlobalScope(OrganizationScope::class)->where('external_reference', 'J-2')->exists());
    }

    public function test_a_queued_product_import_fails_once_the_membership_is_withdrawn(): void
    {
        $this->addMember($this->beta, $this->user, 'admin');
        Storage::fake('local');
        Storage::disk('local')->put('imports/products.csv', "sku,name,price,stock\nLATE-1,Widget,5,10\n");
        $job = new \App\Jobs\ProcessProductImportJob($this->beta->id, $this->user->id, 'local', 'imports/products.csv');

        app(OrganizationMembershipService::class)->remove($this->beta, $this->user);

        try {
            $job->handle();
            $this->fail('The import must not run.');
        } catch (ModelNotFoundException) {
            // expected
        }

        $this->assertFalse(Product::withoutGlobalScope(OrganizationScope::class)->where('sku', 'LATE-1')->exists());
    }

    public function test_a_scheduled_report_runs_as_its_owner_in_the_schedule_organization(): void
    {
        CarbonImmutable::setTestNow('2026-06-30 12:00:00');
        Mail::fake();
        $this->addMember($this->beta, $this->user, 'admin');
        $recipient = $this->homeUser($this->beta, 'admin', 'Reader');

        $report = SavedReport::withoutGlobalScope(OrganizationScope::class)->create([
            'organization_id' => $this->beta->id, 'created_by' => $this->user->id, 'name' => 'Beta stock',
            'data_source' => 'products', 'columns' => ['name', 'sku', 'stock'], 'is_shared' => false,
        ]);
        ReportSchedule::withoutGlobalScopes()->create([
            'organization_id' => $this->beta->id, 'saved_report_id' => $report->id, 'created_by' => $this->user->id,
            'frequency' => 'daily', 'time_of_day' => '08:00', 'format' => 'csv', 'recipients' => [$recipient->email],
            'is_active' => true, 'next_run_at' => CarbonImmutable::parse('2026-06-30 08:00:00'),
        ]);

        $this->assertSame(1, app(ScheduledReportRunner::class)->runDue(now())['sent']);
        Mail::assertSent(ScheduledReportEmail::class, fn (ScheduledReportEmail $m) => str_contains($m->attachmentContent(), 'BETA-1')
            && ! str_contains($m->attachmentContent(), 'ALPHA-1'));

        CarbonImmutable::setTestNow();
    }

    public function test_a_scheduled_report_is_skipped_once_its_owner_left_the_organization(): void
    {
        CarbonImmutable::setTestNow('2026-06-30 12:00:00');
        Mail::fake();
        $this->addMember($this->beta, $this->user, 'admin');
        $recipient = $this->homeUser($this->beta, 'admin', 'Reader');

        $report = SavedReport::withoutGlobalScope(OrganizationScope::class)->create([
            'organization_id' => $this->beta->id, 'created_by' => $this->user->id, 'name' => 'Beta stock',
            'data_source' => 'products', 'columns' => ['name', 'sku'], 'is_shared' => false,
        ]);
        ReportSchedule::withoutGlobalScopes()->create([
            'organization_id' => $this->beta->id, 'saved_report_id' => $report->id, 'created_by' => $this->user->id,
            'frequency' => 'daily', 'time_of_day' => '08:00', 'format' => 'csv', 'recipients' => [$recipient->email],
            'is_active' => true, 'next_run_at' => CarbonImmutable::parse('2026-06-30 08:00:00'),
        ]);
        app(OrganizationMembershipService::class)->remove($this->beta, $this->user);

        $this->assertSame(0, app(ScheduledReportRunner::class)->runDue(now())['sent']);
        Mail::assertNothingSent();

        CarbonImmutable::setTestNow();
    }
}
