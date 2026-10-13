<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations;

use App\Enums\Permission;
use App\Models\Auth\Organization;
use App\Models\CustomerContact;
use App\Models\Inventory\CycleCountSchedule;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Notification;
use App\Models\Order\Order;
use App\Models\Order\ReturnOrder;
use App\Models\ReportSchedule;
use App\Models\SavedReport;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ApprovalService;
use App\Services\NotificationService;
use App\Services\Organizations\ActiveOrganization;
use App\Services\Organizations\OrganizationMembershipService;
use App\Services\Reports\ScheduledReportRunner;
use App\Services\UserActivityAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Organizations\Concerns\BuildsOrganizations;
use Tests\TestCase;

/**
 * Organization-wide notifications, scheduled report recipients and user
 * pickers address the organization's MEMBERS: its home users and the users
 * added to it from other organizations. A withdrawn member drops out, and
 * every permission filter is evaluated in the organization the notification
 * or picker belongs to.
 *
 * Beta is the organization under test. The guest's home is Alpha, where they
 * are an administrator; in Beta they are a plain member holding only what a
 * Beta role grants them.
 */
final class MemberRecipientsTest extends TestCase
{
    use BuildsOrganizations, RefreshDatabase;

    private const GRANTED = [
        'manage_stock', 'view_orders', 'manage_returns', 'view_reports', 'view_products',
        'approve_stock_adjustments', 'view_stock_audits', 'manage_stock_audits',
    ];

    private Organization $alpha;

    private Organization $beta;

    /** Beta's own administrator. */
    private User $owner;

    /** Home in Alpha (admin there), plain member of Beta with GRANTED there. */
    private User $guest;

    /** Was a member of Beta with GRANTED there, then withdrawn. */
    private User $leaver;

    /** Alpha administrator who never belonged to Beta. */
    private User $outsider;

    private Product $betaProduct;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markInstalled();
        Mail::fake();

        $this->alpha = $this->organization('Alpha');
        $this->beta = $this->organization('Beta');

        $this->owner = $this->homeUser($this->beta, 'admin', 'Owner');
        $this->guest = $this->homeUser($this->alpha, 'admin', 'Guest');
        $this->leaver = $this->homeUser($this->alpha, 'admin', 'Leaver');
        $this->outsider = $this->homeUser($this->alpha, 'admin', 'Outsider');

        $this->addMember($this->beta, $this->guest);
        $this->grantInOrganization($this->guest, $this->beta, self::GRANTED);

        $this->addMember($this->beta, $this->leaver);
        $this->grantInOrganization($this->leaver, $this->beta, self::GRANTED);
        app(OrganizationMembershipService::class)->remove($this->beta, $this->leaver);

        $this->betaProduct = $this->product($this->beta, 'BETA-1', ['stock' => 0, 'min_stock' => 5]);
    }

    private function notifiedIn(User $user, string $type): ?Notification
    {
        return Notification::query()->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->first();
    }

    private function assertOnlyMembersNotified(string $type): void
    {
        $note = $this->notifiedIn($this->guest, $type);
        $this->assertNotNull($note, "The member from another organization gets {$type}.");
        $this->assertSame($this->beta->id, (int) $note->organization_id, "{$type} is created in the organization it is about.");
        $this->assertNull($this->notifiedIn($this->leaver, $type), "A withdrawn member gets no {$type}.");
        $this->assertNull($this->notifiedIn($this->outsider, $type), "A user of another organization gets no {$type}.");
    }

    // ==================== Membership query ====================

    public function test_member_of_scope_lists_home_users_and_added_members_only(): void
    {
        $ids = User::query()->memberOf($this->beta->id)->pluck('id')->sort()->values()->all();

        $this->assertSame([$this->owner->id, $this->guest->id], $ids);
    }

    public function test_members_in_evaluates_permissions_in_that_organization(): void
    {
        // Admin at home, plain member in Beta: no admin rights there.
        $plain = $this->homeUser($this->alpha, 'admin', 'Plain');
        $this->addMember($this->beta, $plain);

        $members = app(ActiveOrganization::class)->membersIn($this->beta->id, Permission::MANAGE_STOCK);

        $this->assertEqualsCanonicalizing([$this->owner->id, $this->guest->id], $members->pluck('id')->all());
        $members->each(fn (User $member) => $this->assertSame($this->beta->id, (int) $member->organization_id));
        $this->assertSame($this->alpha->id, $this->guest->fresh()->organization_id, 'Nothing is written to the account.');
    }

    public function test_a_permission_held_only_at_home_does_not_make_a_recipient_elsewhere(): void
    {
        $homeStockManager = $this->homeUser($this->alpha, 'member', 'Stocky');
        $this->grantInOrganization($homeStockManager, $this->alpha, ['manage_stock']);
        $this->addMember($this->beta, $homeStockManager);

        NotificationService::createOutOfStockNotification($this->betaProduct);

        $this->assertNull($this->notifiedIn($homeStockManager, 'out_of_stock'));
        $this->assertNull($this->notifiedIn($this->outsider, 'out_of_stock'));
    }

    // ==================== Organization-wide notifications ====================

    public function test_low_stock_reaches_members(): void
    {
        NotificationService::createLowStockNotification($this->betaProduct);

        $this->assertOnlyMembersNotified('low_stock');
        Mail::assertQueued(\App\Mail\LowStockEmail::class, fn ($mail) => $mail->hasTo($this->guest->email));
        Mail::assertNotQueued(\App\Mail\LowStockEmail::class, fn ($mail) => $mail->hasTo($this->leaver->email));
    }

    public function test_out_of_stock_reaches_members(): void
    {
        NotificationService::createOutOfStockNotification($this->betaProduct);

        $this->assertOnlyMembersNotified('out_of_stock');
    }

    public function test_emails_go_to_the_members_current_address(): void
    {
        $this->guest->update(['email' => 'renamed@user.test']);

        NotificationService::createOutOfStockNotification($this->betaProduct);

        Mail::assertQueued(\App\Mail\LowStockEmail::class, fn ($mail) => $mail->hasTo('renamed@user.test'));
    }

    public function test_variant_stock_alerts_reach_members(): void
    {
        $product = $this->product($this->beta, 'TEE', ['has_variants' => true]);
        $variant = ProductVariant::withoutGlobalScopes()->create([
            'product_id' => $product->id, 'organization_id' => $this->beta->id,
            'sku' => 'TEE-S', 'title' => 'S', 'option_values' => ['Size' => 'S'],
            'stock' => 0, 'min_stock' => 2, 'is_active' => true,
        ]);

        NotificationService::createVariantStockNotification($variant, 'out_of_stock');

        $this->assertOnlyMembersNotified('out_of_stock');
    }

    public function test_warehouse_low_stock_reaches_members(): void
    {
        $warehouse = Warehouse::factory()->create(['organization_id' => $this->beta->id, 'is_active' => true]);

        NotificationService::createWarehouseLowStockNotification($this->betaProduct, $warehouse, 0, 5);

        $this->assertOnlyMembersNotified('warehouse_low_stock');
    }

    public function test_order_created_reaches_members(): void
    {
        $order = (new Order)->forceFill([
            'id' => 1, 'organization_id' => $this->beta->id, 'created_by' => $this->owner->id,
            'order_number' => 'ORD-1', 'customer_name' => 'Customer', 'total' => 10,
        ]);

        NotificationService::createOrderCreatedNotification($order);

        $this->assertOnlyMembersNotified('order_created');
    }

    public function test_portal_return_requests_reach_members(): void
    {
        $order = (new Order)->forceFill(['id' => 1, 'organization_id' => $this->beta->id, 'order_number' => 'ORD-1']);
        $return = (new ReturnOrder)->forceFill(['id' => 1, 'organization_id' => $this->beta->id, 'return_number' => 'RET-1']);
        $contact = (new CustomerContact)->forceFill(['id' => 1, 'customer_id' => 1, 'name' => 'Casey']);

        NotificationService::createPortalReturnRequestedNotification($return, $order, $contact);

        $this->assertOnlyMembersNotified('portal_return_requested');
    }

    public function test_approval_requests_reach_member_approvers_in_the_request_organization(): void
    {
        $requester = app(ActiveOrganization::class)->userIn($this->owner, $this->beta->id);

        app(ApprovalService::class)->requestStockAdjustment($requester, $this->betaProduct, null, 2, 'recount', 'Found two');

        $this->assertOnlyMembersNotified('approval_requested');
    }

    public function test_notifications_to_one_person_stop_once_they_left(): void
    {
        $order = (new Order)->forceFill([
            'id' => 1, 'organization_id' => $this->beta->id, 'created_by' => $this->leaver->id,
            'order_number' => 'ORD-1', 'customer_name' => 'Customer', 'total' => 10, 'status' => 'shipped',
        ]);

        NotificationService::createOrderShippedNotification($order);
        NotificationService::createOrderDeliveredNotification($order);

        $this->assertNull($this->notifiedIn($this->leaver, 'order_shipped'));
        $this->assertNull($this->notifiedIn($this->leaver, 'order_delivered'));

        $order->created_by = $this->guest->id;
        NotificationService::createOrderShippedNotification($order);
        $this->assertSame($this->beta->id, (int) $this->notifiedIn($this->guest, 'order_shipped')?->organization_id);
    }

    public function test_user_activity_alerts_reach_member_administrators_only(): void
    {
        $guestAdmin = $this->homeUser($this->alpha, 'member', 'GuestAdmin');
        $this->addMember($this->beta, $guestAdmin, 'admin');
        foreach ([$guestAdmin, $this->guest, $this->owner] as $user) {
            $user->update(['notification_preferences' => [UserActivityAlertService::PREFERENCE_KEY => true]]);
        }

        $recipients = app(UserActivityAlertService::class)->recipients($this->beta->id)->pluck('id')->all();

        // The guest is an administrator at home only: not one in Beta.
        $this->assertEqualsCanonicalizing([$this->owner->id, $guestAdmin->id], $recipients);
    }

    public function test_a_cycle_count_assignee_who_left_is_not_notified(): void
    {
        $schedule = CycleCountSchedule::withoutGlobalScope(OrganizationScope::class)->create([
            'organization_id' => $this->beta->id, 'name' => 'Weekly', 'frequency' => 'weekly',
            'scope_type' => 'all', 'products_per_run' => 5, 'assigned_to' => $this->guest->id,
            'created_by' => $this->owner->id, 'is_active' => true, 'next_run_at' => now()->subMinute(),
        ]);

        app(\App\Services\CycleCountService::class)->run($schedule);
        $note = $this->notifiedIn($this->guest, 'cycle_count_assigned');
        $this->assertSame($this->beta->id, (int) $note?->organization_id);

        Notification::query()->withoutGlobalScopes()->delete();
        app(OrganizationMembershipService::class)->remove($this->beta, $this->guest);
        $later = $schedule->replicate(['last_audit_id', 'last_run_at']);
        $later->save();

        $this->assertNotNull(app(\App\Services\CycleCountService::class)->run($later));
        $this->assertNull($this->notifiedIn($this->guest, 'cycle_count_assigned'));
    }

    // ==================== Scheduled reports ====================

    private function savedReport(): SavedReport
    {
        return SavedReport::withoutGlobalScope(OrganizationScope::class)->create([
            'organization_id' => $this->beta->id, 'created_by' => $this->owner->id, 'name' => 'Stock',
            'data_source' => 'products', 'columns' => ['name', 'sku', 'stock'], 'is_shared' => false,
        ]);
    }

    public function test_report_recipient_options_list_members_who_can_see_the_report(): void
    {
        $report = $this->savedReport();
        $this->actingAs($this->owner);

        $response = $this->get(route('reports.builder.show', $report))->assertOk();
        $emails = collect($response->viewData('page')['props']['recipientOptions'])->pluck('email')->all();

        $this->assertContains($this->guest->email, $emails);
        $this->assertNotContains($this->leaver->email, $emails);
        $this->assertNotContains($this->outsider->email, $emails);
    }

    public function test_report_schedules_accept_members_and_refuse_everyone_else(): void
    {
        $report = $this->savedReport();
        $this->actingAs($this->owner);
        $payload = ['frequency' => 'daily', 'time_of_day' => '08:00', 'format' => 'csv'];

        $this->post(route('reports.builder.schedules.store', $report), $payload + ['recipients' => [$this->guest->email]])
            ->assertSessionHasNoErrors();

        foreach ([$this->leaver, $this->outsider] as $user) {
            $this->post(route('reports.builder.schedules.store', $report), $payload + ['recipients' => [$user->email]])
                ->assertSessionHasErrors('recipients');
        }
    }

    public function test_report_recipients_need_the_permission_in_the_report_organization(): void
    {
        $report = $this->savedReport();
        // Holds view_reports at home only.
        $homeReader = $this->homeUser($this->alpha, 'member', 'Reader');
        $this->grantInOrganization($homeReader, $this->alpha, ['view_reports', 'view_products']);
        $this->addMember($this->beta, $homeReader);
        $this->actingAs($this->owner);

        $this->post(route('reports.builder.schedules.store', $report), [
            'frequency' => 'daily', 'time_of_day' => '08:00', 'format' => 'csv', 'recipients' => [$homeReader->email],
        ])->assertSessionHasErrors('recipients');
    }

    public function test_scheduled_reports_are_sent_to_members_and_stop_for_leavers(): void
    {
        Queue::fake();
        $report = $this->savedReport();
        $schedule = ReportSchedule::withoutGlobalScopes()->create([
            'organization_id' => $this->beta->id, 'saved_report_id' => $report->id, 'created_by' => $this->owner->id,
            'frequency' => 'daily', 'time_of_day' => '08:00', 'format' => 'csv',
            'recipients' => [mb_strtolower($this->guest->email), mb_strtolower($this->leaver->email)],
            'is_active' => true, 'next_run_at' => now()->subMinute(),
        ]);

        app(ScheduledReportRunner::class)->send($schedule);

        Queue::assertPushed(\App\Jobs\DeliverScheduledReportJob::class, fn ($job) => $job->recipients === [mb_strtolower($this->guest->email)]);
    }

    // ==================== Pickers and validation ====================

    public function test_cycle_count_assignee_picker_and_validation_use_membership(): void
    {
        $this->actingAs($this->owner);

        $ids = collect($this->get(route('cycle-counts.create'))->assertOk()->viewData('page')['props']['users'])
            ->pluck('id')->all();
        $this->assertContains($this->guest->id, $ids);
        $this->assertNotContains($this->leaver->id, $ids);
        $this->assertNotContains($this->outsider->id, $ids);

        $payload = ['name' => 'Weekly', 'frequency' => 'weekly', 'scope_type' => 'all', 'products_per_run' => 5];
        $this->post(route('cycle-counts.store'), $payload + ['assigned_to' => $this->guest->id])->assertSessionHasNoErrors();
        foreach ([$this->leaver, $this->outsider] as $user) {
            $this->post(route('cycle-counts.store'), $payload + ['assigned_to' => $user->id])->assertSessionHasErrors('assigned_to');
        }
    }

    public function test_warehouse_user_picker_and_validation_use_membership(): void
    {
        $warehouse = Warehouse::factory()->create(['organization_id' => $this->beta->id, 'is_active' => true]);
        $this->actingAs($this->owner);

        $users = collect($this->get(route('warehouses.edit', $warehouse))->assertOk()->viewData('page')['props']['users']);
        $this->assertContains($this->guest->id, $users->pluck('id')->all());
        $this->assertNotContains($this->leaver->id, $users->pluck('id')->all());
        $this->assertNotContains($this->outsider->id, $users->pluck('id')->all());
        // Admin at home, plain member here: an assignment does restrict them.
        $this->assertFalse($users->firstWhere('id', $this->guest->id)['has_all_warehouse_access']);

        $this->post(route('warehouses.users.update', $warehouse), ['user_ids' => [$this->guest->id]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('warehouse_user', ['warehouse_id' => $warehouse->id, 'user_id' => $this->guest->id]);
        foreach ([$this->leaver, $this->outsider] as $user) {
            $this->post(route('warehouses.users.update', $warehouse), ['user_ids' => [$user->id]])->assertSessionHasErrors('user_ids.0');
        }
    }

    public function test_filter_pickers_list_members(): void
    {
        $this->actingAs($this->owner);

        foreach (['stock-adjustments.index', 'activity-log.index'] as $route) {
            $ids = collect($this->get(route($route))->assertOk()->viewData('page')['props']['users'])->pluck('id')->all();
            $this->assertContains($this->guest->id, $ids, $route);
            $this->assertNotContains($this->leaver->id, $ids, $route);
            $this->assertNotContains($this->outsider->id, $ids, $route);
        }
    }

    public function test_the_users_screen_still_lists_home_users_only(): void
    {
        $this->actingAs($this->owner);

        $ids = collect($this->get(route('users.index'))->assertOk()->viewData('page')['props']['users']['data'])->pluck('id')->all();

        $this->assertSame([$this->owner->id], $ids);
    }
}
