<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeliverScheduledReportJob;
use App\Mail\ScheduledReportEmail;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\ReportSchedule;
use App\Models\Role;
use App\Models\SavedReport;
use App\Models\Setting;
use App\Models\System\SystemSetting;
use App\Models\User;
use App\Services\Reports\ScheduledReportRunner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Scheduled delivery of saved reports: due-time calculation in the org's time
 * zone, the send-time permission re-check against the report OWNER, the
 * attachment in each format, recipient and organization scoping, and the
 * management routes on the saved report.
 */
class ScheduledReportsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private Role $ownerRole;

    private User $colleague;

    private SavedReport $report;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', true, 'boolean');
        CarbonImmutable::setTestNow('2026-06-30 12:00:00');
        Carbon::setTestNow('2026-06-30 12:00:00');

        [$this->org, $this->owner, $this->ownerRole, $this->colleague, $this->report] = $this->seedOrg('acme', 'ACME-WIDGET');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0: Organization, 1: User, 2: Role, 3: User, 4: SavedReport} */
    private function seedOrg(string $slug, string $sku, string $timezone = 'UTC'): array
    {
        $org = Organization::create(['name' => ucfirst($slug), 'email' => "{$slug}@org.test", 'currency' => 'USD', 'timezone' => $timezone]);

        $role = Role::create([
            'name' => "Analyst {$slug}", 'slug' => "analyst-{$slug}", 'is_system' => false,
            'permissions' => ['view_reports', 'view_products'],
        ]);
        $owner = User::create([
            'name' => "Owner {$slug}", 'email' => "owner@{$slug}.test", 'password' => bcrypt('x'),
            'organization_id' => $org->id, 'role' => 'member',
        ]);
        $owner->roles()->syncWithoutDetaching([$role->id]);

        $colleague = User::create([
            'name' => "Colleague {$slug}", 'email' => "colleague@{$slug}.test", 'password' => bcrypt('x'),
            'organization_id' => $org->id, 'role' => 'member',
        ]);
        // Recipients must themselves be able to see the report's data.
        $readerRole = Role::create([
            'name' => "Reader {$slug}", 'slug' => "reader-{$slug}", 'is_system' => false,
            'permissions' => ['view_reports', 'view_products'],
        ]);
        $colleague->roles()->syncWithoutDetaching([$readerRole->id]);

        Product::create([
            'organization_id' => $org->id, 'name' => "Product {$sku}", 'sku' => $sku,
            'price' => 10, 'currency' => 'USD', 'stock' => 4,
        ]);

        $report = SavedReport::create([
            'organization_id' => $org->id, 'created_by' => $owner->id, 'name' => "Stock {$slug}",
            'data_source' => 'products', 'columns' => ['name', 'sku', 'stock'], 'is_shared' => false,
        ]);

        return [$org, $owner, $role, $colleague, $report];
    }

    /** @param array<string, mixed> $attrs */
    private function schedule(array $attrs = [], ?SavedReport $report = null): ReportSchedule
    {
        $report ??= $this->report;

        return ReportSchedule::withoutGlobalScopes()->create(array_merge([
            'organization_id' => $report->organization_id,
            'saved_report_id' => $report->id,
            'created_by' => $report->created_by,
            'frequency' => 'daily',
            'time_of_day' => '08:00',
            'format' => 'csv',
            'recipients' => [$this->colleague->email],
            'is_active' => true,
            'next_run_at' => CarbonImmutable::parse('2026-06-30 08:00:00'),
        ], $attrs));
    }

    private function runner(): ScheduledReportRunner
    {
        return app(ScheduledReportRunner::class);
    }

    // ------------------------------------------------------------ due times

    public function test_daily_runs_at_the_next_occurrence_of_the_time(): void
    {
        $this->assertSame('2026-06-30 08:00', ReportSchedule::nextRunAfter('daily', null, null, '08:00', 'UTC', CarbonImmutable::parse('2026-06-30 07:59'))->format('Y-m-d H:i'));
        $this->assertSame('2026-07-01 08:00', ReportSchedule::nextRunAfter('daily', null, null, '08:00', 'UTC', CarbonImmutable::parse('2026-06-30 08:00'))->format('Y-m-d H:i'));
    }

    public function test_weekly_runs_on_the_chosen_weekday(): void
    {
        // 2026-06-30 is a Tuesday; 1 = Monday.
        $next = ReportSchedule::nextRunAfter('weekly', 1, null, '08:00', 'UTC', CarbonImmutable::parse('2026-06-30 12:00'));
        $this->assertSame('2026-07-06 08:00 Mon', $next->format('Y-m-d H:i D'));

        // Same weekday but the time has not passed yet: today.
        $today = ReportSchedule::nextRunAfter('weekly', 2, null, '18:00', 'UTC', CarbonImmutable::parse('2026-06-30 12:00'));
        $this->assertSame('2026-06-30 18:00', $today->format('Y-m-d H:i'));
    }

    public function test_monthly_clamps_to_the_last_day_of_short_months(): void
    {
        $this->assertSame('2026-07-31 08:00', ReportSchedule::nextRunAfter('monthly', null, 31, '08:00', 'UTC', CarbonImmutable::parse('2026-06-30 09:00'))->format('Y-m-d H:i'));
        $this->assertSame('2026-06-30 08:00', ReportSchedule::nextRunAfter('monthly', null, 31, '08:00', 'UTC', CarbonImmutable::parse('2026-06-15 09:00'))->format('Y-m-d H:i'));
        $this->assertSame('2026-02-28 08:00', ReportSchedule::nextRunAfter('monthly', null, 31, '08:00', 'UTC', CarbonImmutable::parse('2026-02-01 00:00'))->format('Y-m-d H:i'));
    }

    public function test_the_time_is_in_the_organizations_time_zone_and_stored_in_utc(): void
    {
        // 08:00 in Toronto during daylight time is 12:00 UTC.
        $next = ReportSchedule::nextRunAfter('daily', null, null, '08:00', 'America/Toronto', CarbonImmutable::parse('2026-06-30 11:00', 'UTC'));

        $this->assertSame('UTC', $next->timezone->getName());
        $this->assertSame('2026-06-30 12:00', $next->format('Y-m-d H:i'));
    }

    // -------------------------------------------------------------- sending

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function formats(): array
    {
        return [
            'csv' => ['csv', 'text/csv; charset=UTF-8', "\xEF\xBB\xBF"],
            'xlsx' => ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'PK'],
            'pdf' => ['pdf', 'application/pdf', '%PDF'],
        ];
    }

    #[DataProvider('formats')]
    public function test_a_due_schedule_sends_the_report_with_its_attachment(string $format, string $mime, string $magic): void
    {
        Mail::fake();
        $schedule = $this->schedule(['format' => $format]);

        $stats = $this->runner()->runDue(now());

        $this->assertSame(1, $stats['sent']);
        Mail::assertSent(ScheduledReportEmail::class, function (ScheduledReportEmail $mail) use ($format, $mime, $magic) {
            $content = $mail->attachmentContent();

            return $mail->hasTo('colleague@acme.test')
                && ($mail->data['organization_id'] ?? null) === $this->org->id
                && str_ends_with($mail->filename, '.'.$format)
                && $mail->mimeType === $mime
                && str_starts_with($content, $magic);
        });

        $schedule->refresh();
        $this->assertSame('sent', $schedule->last_status);
        $this->assertSame('2026-07-01 08:00:00', $schedule->next_run_at->format('Y-m-d H:i:s'));
    }

    public function test_the_queued_delivery_carries_no_report_data(): void
    {
        // Report contents must never be serialized into jobs/failed_jobs:
        // the queued job holds ids and addresses and renders in the worker.
        Queue::fake();
        Mail::fake();
        $this->schedule();

        $this->assertSame(1, $this->runner()->runDue(now())['sent']);

        Mail::assertNothingOutgoing();
        Queue::assertPushed(DeliverScheduledReportJob::class, function (DeliverScheduledReportJob $job) {
            $payload = serialize($job);

            return $job->recipients === ['colleague@acme.test']
                && ! str_contains($payload, 'ACME-WIDGET')
                && ! str_contains($payload, 'Product ACME-WIDGET');
        });
    }

    public function test_a_scheduled_report_email_refuses_to_be_queued(): void
    {
        $this->expectException(\LogicException::class);

        (new ScheduledReportEmail($this->org->id, 'Stock acme', 'weekly', 'stock.csv', 'text/csv; charset=UTF-8', "a,b
"))
            ->queue(app('queue'));
    }

    public function test_the_csv_attachment_holds_the_owners_org_data_only(): void
    {
        [, , , , $otherReport] = $this->seedOrg('globex', 'GLOBEX-GADGET');
        Mail::fake();
        $this->schedule();
        $this->schedule(['recipients' => ['colleague@globex.test']], $otherReport);

        $stats = $this->runner()->runDue(now());

        $this->assertSame(2, $stats['sent']);
        Mail::assertSent(ScheduledReportEmail::class, fn (ScheduledReportEmail $m) => $m->hasTo('colleague@acme.test')
            && str_contains($m->attachmentContent(), 'ACME-WIDGET')
            && ! str_contains($m->attachmentContent(), 'GLOBEX-GADGET'));
        Mail::assertSent(ScheduledReportEmail::class, fn (ScheduledReportEmail $m) => $m->hasTo('colleague@globex.test')
            && str_contains($m->attachmentContent(), 'GLOBEX-GADGET')
            && ! str_contains($m->attachmentContent(), 'ACME-WIDGET'));
    }

    public function test_a_schedule_that_is_not_due_or_is_paused_is_left_alone(): void
    {
        Mail::fake();
        $this->schedule(['next_run_at' => CarbonImmutable::parse('2026-06-30 13:00:00')]);
        $this->schedule(['is_active' => false]);

        $stats = $this->runner()->runDue(now());

        $this->assertSame(0, $stats['sent']);
        Mail::assertNothingOutgoing();
    }

    public function test_an_owner_who_lost_view_reports_is_skipped_and_logged(): void
    {
        Mail::fake();
        Log::spy();
        $schedule = $this->schedule();
        $this->ownerRole->update(['permissions' => ['view_products']]);

        $stats = $this->runner()->runDue(now());

        $this->assertSame(1, $stats['skipped']);
        Mail::assertNothingOutgoing();
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($message, 'Scheduled report skipped')
            && $context['reason'] === 'permission_revoked'
            && $context['schedule_id'] === $schedule->id);

        $schedule->refresh();
        $this->assertSame('skipped', $schedule->last_status);
        // It still moves on to the next slot rather than retrying every tick.
        $this->assertSame('2026-07-01 08:00:00', $schedule->next_run_at->format('Y-m-d H:i:s'));
    }

    public function test_an_owner_who_lost_the_data_source_permission_is_skipped(): void
    {
        Mail::fake();
        $schedule = $this->schedule();
        $this->ownerRole->update(['permissions' => ['view_reports']]);

        $stats = $this->runner()->runDue(now());

        $this->assertSame(1, $stats['skipped']);
        Mail::assertNothingOutgoing();
        $this->assertSame('skipped', $schedule->refresh()->last_status);
    }

    public function test_a_recipient_who_cannot_view_the_report_data_is_not_sent_it(): void
    {
        // The report is generated with the OWNER's access, so each recipient
        // must also hold view_reports and the data source's view permission;
        // otherwise a report owner could mail data to anyone in the org.
        Mail::fake();
        $noAccess = User::create([
            'name' => 'No Access', 'email' => 'noaccess@acme.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);
        $reportsOnly = User::create([
            'name' => 'Reports Only', 'email' => 'reportsonly@acme.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);
        $reportsOnly->roles()->syncWithoutDetaching([Role::create([
            'name' => 'Reports only', 'slug' => 'reports-only', 'is_system' => false, 'permissions' => ['view_reports'],
        ])->id]);
        $this->schedule(['recipients' => [$this->colleague->email, $noAccess->email, $reportsOnly->email]]);

        $this->assertSame(1, $this->runner()->runDue(now())['sent']);

        Mail::assertSent(ScheduledReportEmail::class, 1);
        Mail::assertSent(ScheduledReportEmail::class, fn ($m) => $m->hasTo('colleague@acme.test'));
    }

    public function test_a_schedule_whose_recipients_all_lost_access_is_skipped(): void
    {
        Mail::fake();
        $this->colleague->roles()->detach();
        $this->schedule();

        $this->assertSame(1, $this->runner()->runDue(now())['skipped']);
        Mail::assertNothingOutgoing();
    }

    public function test_recipients_who_are_no_longer_in_the_organization_are_dropped(): void
    {
        Mail::fake();
        [, , , $outsider] = $this->seedOrg('initech', 'INI-1');
        $this->schedule(['recipients' => [$this->colleague->email, 'gone@acme.test', $outsider->email, strtoupper($this->owner->email)]]);

        $this->runner()->runDue(now());

        Mail::assertSent(ScheduledReportEmail::class, 2);
        Mail::assertSent(ScheduledReportEmail::class, fn ($m) => $m->hasTo('colleague@acme.test'));
        Mail::assertSent(ScheduledReportEmail::class, fn ($m) => $m->hasTo('owner@acme.test'));
        Mail::assertNotSent(ScheduledReportEmail::class, fn ($m) => $m->hasTo($outsider->email) || $m->hasTo('gone@acme.test'));
    }

    public function test_a_schedule_is_claimed_once_even_if_two_runs_overlap(): void
    {
        Mail::fake();
        $this->schedule();

        $this->runner()->runDue(now());
        $second = $this->runner()->runDue(now());

        $this->assertSame(0, $second['sent']);
        Mail::assertSent(ScheduledReportEmail::class, 1);
    }

    public function test_the_command_is_registered_and_sends_due_reports(): void
    {
        Mail::fake();
        $this->schedule();

        $this->artisan('reports:send-scheduled')->assertSuccessful();

        Mail::assertSent(ScheduledReportEmail::class, 1);

        $commands = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' ');
        $this->assertStringContainsString('reports:send-scheduled', $commands);
    }

    // --------------------------------------------------------------- mailable

    public function test_the_mailable_applies_org_mail_config_and_has_a_text_part(): void
    {
        Setting::create(['organization_id' => $this->org->id, 'key' => 'email.provider', 'value' => 'smtp', 'encrypted' => false]);
        Setting::create(['organization_id' => $this->org->id, 'key' => 'email.smtp.host', 'value' => 'smtp.acme.test', 'encrypted' => false]);
        Setting::create(['organization_id' => $this->org->id, 'key' => 'email.from_address', 'value' => 'reports@acme.test', 'encrypted' => false]);
        config(['mail.from.address' => 'default@system.test']);

        $mail = new ScheduledReportEmail($this->org->id, 'Stock acme', 'weekly', 'stock.csv', 'text/csv; charset=UTF-8', "a,b\n");
        $mail->build();

        // The org's From rides on its own mailer; the global config is untouched.
        $this->assertSame('reports@acme.test', config("mail.mailers.{$mail->mailer}.from.address"));
        $this->assertSame('default@system.test', config('mail.from.address'));
    }

    public function test_the_mailable_is_branded_with_a_plain_text_part_and_the_attachment(): void
    {
        // Rendering resolves the org's mailer; use one that needs no host.
        Setting::create(['organization_id' => $this->org->id, 'key' => 'email.provider', 'value' => 'array', 'encrypted' => false]);

        $mail = new ScheduledReportEmail($this->org->id, 'Stock acme', 'weekly', 'stock.csv', 'text/csv; charset=UTF-8', "a,b\n");
        $mail->build();

        $this->assertNotNull($mail->textView);
        $this->assertTrue(view()->exists($mail->textView));
        $mail->assertSeeInText('Stock acme');
        $mail->assertSeeInHtml('Acme');
        $mail->assertDontSeeInHtml('Inventoros. All rights reserved.');
        $this->assertTrue($mail->hasAttachedData("a,b\n", 'stock.csv', ['mime' => 'text/csv; charset=UTF-8']));
    }

    // ------------------------------------------------------------- management

    public function test_the_owner_creates_a_schedule_for_org_recipients(): void
    {
        $this->actingAs($this->owner)
            ->post(route('reports.builder.schedules.store', $this->report), [
                'frequency' => 'weekly', 'day_of_week' => 1, 'time_of_day' => '07:30',
                'format' => 'xlsx', 'recipients' => ['Colleague@Acme.test'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $schedule = ReportSchedule::withoutGlobalScopes()->sole();
        $this->assertSame($this->org->id, $schedule->organization_id);
        $this->assertSame($this->owner->id, $schedule->created_by);
        $this->assertSame(['colleague@acme.test'], $schedule->recipients);
        $this->assertSame('2026-07-06 07:30:00', $schedule->next_run_at->format('Y-m-d H:i:s'));
    }

    public function test_recipients_outside_the_organization_are_rejected(): void
    {
        [, , , $outsider] = $this->seedOrg('umbrella', 'UMB-1');

        $this->actingAs($this->owner)
            ->post(route('reports.builder.schedules.store', $this->report), [
                'frequency' => 'daily', 'time_of_day' => '08:00', 'format' => 'csv',
                'recipients' => [$outsider->email],
            ])
            ->assertSessionHasErrors('recipients');

        $this->assertSame(0, ReportSchedule::withoutGlobalScopes()->count());
    }

    public function test_recipients_without_access_to_the_report_data_are_rejected(): void
    {
        $viewer = User::create([
            'name' => 'Viewer', 'email' => 'viewer@acme.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);

        $this->actingAs($this->owner)
            ->post(route('reports.builder.schedules.store', $this->report), [
                'frequency' => 'daily', 'time_of_day' => '08:00', 'format' => 'csv',
                'recipients' => [$this->colleague->email, $viewer->email],
            ])
            ->assertSessionHasErrors('recipients');

        $this->assertSame(0, ReportSchedule::withoutGlobalScopes()->count());
    }

    public function test_only_the_report_owner_manages_its_schedules(): void
    {
        $this->report->update(['is_shared' => true]);
        $viewer = $this->colleague;
        $viewer->roles()->syncWithoutDetaching([$this->ownerRole->id]);
        $schedule = $this->schedule();

        $payload = ['frequency' => 'daily', 'time_of_day' => '08:00', 'format' => 'csv', 'recipients' => [$viewer->email]];

        $this->actingAs($viewer)->post(route('reports.builder.schedules.store', $this->report), $payload)->assertForbidden();
        $this->actingAs($viewer)->put(route('reports.builder.schedules.update', [$this->report, $schedule]), $payload)->assertForbidden();
        $this->actingAs($viewer)->delete(route('reports.builder.schedules.destroy', [$this->report, $schedule]))->assertForbidden();
    }

    public function test_another_organization_cannot_reach_a_schedule(): void
    {
        [, $otherOwner, , , $otherReport] = $this->seedOrg('hooli', 'HOO-1');
        $schedule = $this->schedule();

        // Their own report in the URL, our schedule id: the schedule is not theirs.
        $this->actingAs($otherOwner)
            ->delete(route('reports.builder.schedules.destroy', [$otherReport, $schedule]))
            ->assertNotFound();
        $this->assertNotNull(ReportSchedule::withoutGlobalScopes()->find($schedule->id));
    }

    public function test_the_owner_pauses_updates_and_deletes_a_schedule(): void
    {
        $schedule = $this->schedule();

        $this->actingAs($this->owner)
            ->put(route('reports.builder.schedules.update', [$this->report, $schedule]), [
                'frequency' => 'monthly', 'day_of_month' => 31, 'time_of_day' => '09:00', 'format' => 'pdf',
                'recipients' => [$this->colleague->email], 'is_active' => false,
            ])
            ->assertSessionHasNoErrors();

        $schedule->refresh();
        $this->assertFalse($schedule->is_active);
        $this->assertSame('monthly', $schedule->frequency);
        $this->assertSame('2026-07-31 09:00:00', $schedule->next_run_at->format('Y-m-d H:i:s'));

        $this->actingAs($this->owner)
            ->delete(route('reports.builder.schedules.destroy', [$this->report, $schedule]))
            ->assertRedirect();
        $this->assertNull(ReportSchedule::withoutGlobalScopes()->find($schedule->id));
    }

    public function test_the_recipient_picker_only_offers_people_who_may_see_the_report(): void
    {
        User::create([
            'name' => 'No Access', 'email' => 'noaccess@acme.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'member',
        ]);

        $this->actingAs($this->owner)
            ->get(route('reports.builder.show', $this->report))
            ->assertInertia(fn (Assert $page) => $page
                ->has('recipientOptions', 2)
                ->where('recipientOptions.0.email', 'colleague@acme.test')
                ->where('recipientOptions.1.email', 'owner@acme.test')
            );
    }

    public function test_the_show_page_lists_schedules_for_the_owner_only(): void
    {
        $this->schedule();
        $this->report->update(['is_shared' => true]);
        $this->colleague->roles()->syncWithoutDetaching([$this->ownerRole->id]);

        $this->actingAs($this->owner)
            ->get(route('reports.builder.show', $this->report))
            ->assertInertia(fn (Assert $page) => $page
                ->has('schedules', 1)
                ->where('schedules.0.frequency', 'daily')
                ->has('recipientOptions', 2)
                ->where('scheduleOptions.timezone', 'UTC')
            );

        $this->actingAs($this->colleague)
            ->get(route('reports.builder.show', $this->report))
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedules', [])
                ->where('recipientOptions', [])
            );
    }
}
