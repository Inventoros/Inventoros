# Email Notifications System Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Implement comprehensive email notification system with configurable email providers (SMTP, PHP Mail, Mailgun, SendGrid), organization-scoped settings, and email notifications for low stock, order status changes, and order approvals.

**Architecture:** Database-driven organization-scoped settings with encrypted sensitive data, reusable SettingsService for configuration management, extended NotificationService for email sending with plugin hooks, and Vue.js settings UI with tabbed interface.

**Tech Stack:** Laravel 11, Vue 3 Composition API, Inertia.js, Tailwind CSS, Laravel Mail (Mailables), Blade templates for emails

---

## Task 1: Create Database Migrations

**Files:**
- Create: `database/migrations/YYYY_MM_DD_create_settings_table.php`
- Create: `database/migrations/YYYY_MM_DD_create_email_logs_table.php`

**Step 1: Create settings table migration**

```bash
cd .worktrees/email-notifications
php artisan make:migration create_settings_table
```

**Step 2: Write settings migration**

Edit the generated migration file:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key')->index();
            $table->text('value')->nullable();
            $table->boolean('encrypted')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
```

**Step 3: Create email_logs table migration**

```bash
php artisan make:migration create_email_logs_table
```

**Step 4: Write email_logs migration**

Edit the generated migration file:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->enum('status', ['sent', 'failed']);
            $table->text('error_message')->nullable();
            $table->timestamp('created_at');

            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
```

**Step 5: Run migrations**

```bash
php artisan migrate
```

Expected: Both tables created successfully

**Step 6: Commit**

```bash
git add database/migrations/
git commit -m "Add settings and email_logs database migrations"
```

---

## Task 2: Create Setting Model

**Files:**
- Create: `app/Models/Setting.php`

**Step 1: Create Setting model**

```bash
php artisan make:model Setting
```

**Step 2: Write Setting model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    protected $fillable = [
        'organization_id',
        'key',
        'value',
        'encrypted',
    ];

    protected $casts = [
        'encrypted' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
```

**Step 3: Commit**

```bash
git add app/Models/Setting.php
git commit -m "Add Setting model with organization relationship"
```

---

## Task 3: Create SettingsService

**Files:**
- Create: `app/Services/SettingsService.php`

**Step 1: Create SettingsService class**

```php
<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    /**
     * Get a setting value for current organization.
     */
    public static function get(string $key, $default = null)
    {
        $organizationId = auth()->user()->organization_id;

        return Cache::remember("settings.{$organizationId}.{$key}", 3600, function () use ($organizationId, $key, $default) {
            $setting = Setting::where('organization_id', $organizationId)
                ->where('key', $key)
                ->first();

            if (!$setting) {
                return $default;
            }

            return $setting->encrypted ? decrypt($setting->value) : $setting->value;
        });
    }

    /**
     * Set a setting value.
     */
    public static function set(string $key, $value, bool $encrypted = false): void
    {
        $organizationId = auth()->user()->organization_id;

        Setting::updateOrCreate(
            [
                'organization_id' => $organizationId,
                'key' => $key
            ],
            [
                'value' => $encrypted ? encrypt($value) : $value,
                'encrypted' => $encrypted
            ]
        );

        Cache::forget("settings.{$organizationId}.{$key}");
    }

    /**
     * Get all email settings as array.
     */
    public static function getEmailConfig(): array
    {
        return [
            'provider' => self::get('email.provider', 'smtp'),
            'from_address' => self::get('email.from_address'),
            'from_name' => self::get('email.from_name'),
            'smtp' => [
                'host' => self::get('email.smtp.host'),
                'port' => self::get('email.smtp.port', 587),
                'username' => self::get('email.smtp.username'),
                'password' => self::get('email.smtp.password'),
                'encryption' => self::get('email.smtp.encryption', 'tls'),
            ],
            'mailgun' => [
                'domain' => self::get('email.mailgun.domain'),
                'secret' => self::get('email.mailgun.secret'),
            ],
            'sendgrid' => [
                'api_key' => self::get('email.sendgrid.api_key'),
            ],
        ];
    }

    /**
     * Apply email configuration to Laravel's mail config.
     */
    public static function applyEmailConfig(): void
    {
        $config = self::getEmailConfig();

        \Config::set('mail.default', $config['provider']);
        \Config::set('mail.from.address', $config['from_address']);
        \Config::set('mail.from.name', $config['from_name']);

        switch ($config['provider']) {
            case 'smtp':
                \Config::set('mail.mailers.smtp.host', $config['smtp']['host']);
                \Config::set('mail.mailers.smtp.port', $config['smtp']['port']);
                \Config::set('mail.mailers.smtp.username', $config['smtp']['username']);
                \Config::set('mail.mailers.smtp.password', $config['smtp']['password']);
                \Config::set('mail.mailers.smtp.encryption', $config['smtp']['encryption']);
                break;

            case 'mailgun':
                \Config::set('services.mailgun.domain', $config['mailgun']['domain']);
                \Config::set('services.mailgun.secret', $config['mailgun']['secret']);
                break;

            case 'sendgrid':
                \Config::set('services.sendgrid.api_key', $config['sendgrid']['api_key']);
                break;
        }
    }
}
```

**Step 2: Commit**

```bash
git add app/Services/SettingsService.php
git commit -m "Add SettingsService for organization settings management"
```

---

## Task 4: Create EmailLogger Service

**Files:**
- Create: `app/Services/EmailLogger.php`

**Step 1: Create EmailLogger class**

```php
<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class EmailLogger
{
    public static function logSent(string $type, User $user, array $data): void
    {
        DB::table('email_logs')->insert([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'type' => $type,
            'status' => 'sent',
            'created_at' => now(),
        ]);
    }

    public static function logFailed(string $type, User $user, \Exception $e): void
    {
        DB::table('email_logs')->insert([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'type' => $type,
            'status' => 'failed',
            'error_message' => $e->getMessage(),
            'created_at' => now(),
        ]);
    }
}
```

**Step 2: Commit**

```bash
git add app/Services/EmailLogger.php
git commit -m "Add EmailLogger service for tracking email delivery"
```

---

## Task 5: Create Mailable Classes

**Files:**
- Create: `app/Mail/LowStockEmail.php`
- Create: `app/Mail/OrderStatusEmail.php`
- Create: `app/Mail/OrderApprovalEmail.php`
- Create: `app/Mail/TestEmail.php`

**Step 1: Create LowStockEmail mailable**

```bash
php artisan make:mail LowStockEmail
```

Edit the file:

```php
<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LowStockEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $data) {}

    public function build()
    {
        return $this->subject('Low Stock Alert - ' . $this->data['product']->name)
            ->view('emails.low-stock-alert')
            ->with($this->data);
    }
}
```

**Step 2: Create OrderStatusEmail mailable**

```bash
php artisan make:mail OrderStatusEmail
```

Edit the file:

```php
<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OrderStatusEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $data) {}

    public function build()
    {
        return $this->subject('Order Status Updated - #' . $this->data['order']->order_number)
            ->view('emails.order-status')
            ->with($this->data);
    }
}
```

**Step 3: Create OrderApprovalEmail mailable**

```bash
php artisan make:mail OrderApprovalEmail
```

Edit the file:

```php
<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OrderApprovalEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $data) {}

    public function build()
    {
        $status = $this->data['order']->approval_status;

        return $this->subject('Order ' . ucfirst($status) . ' - #' . $this->data['order']->order_number)
            ->view('emails.order-approval')
            ->with($this->data);
    }
}
```

**Step 4: Create TestEmail mailable**

```bash
php artisan make:mail TestEmail
```

Edit the file:

```php
<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TestEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $data) {}

    public function build()
    {
        return $this->subject('Test Email - Inventoros')
            ->view('emails.test-email')
            ->with($this->data);
    }
}
```

**Step 5: Commit**

```bash
git add app/Mail/
git commit -m "Add Mailable classes for email notifications"
```

---

## Task 6: Create Email Layout Template

**Files:**
- Create: `resources/views/emails/layout.blade.php`

**Step 1: Create emails directory**

```bash
mkdir -p resources/views/emails
```

**Step 2: Create layout template**

Create `resources/views/emails/layout.blade.php` with the complete layout from the design document (the full HTML email template with header, content section, and footer).

**Step 3: Commit**

```bash
git add resources/views/emails/layout.blade.php
git commit -m "Add base email layout template with responsive design"
```

---

## Task 7: Create Email Notification Templates

**Files:**
- Create: `resources/views/emails/low-stock-alert.blade.php`
- Create: `resources/views/emails/order-status.blade.php`
- Create: `resources/views/emails/order-approval.blade.php`
- Create: `resources/views/emails/test-email.blade.php`

**Step 1-4: Create each template**

Create all four email templates using the designs from the design document. Each extends the layout and provides specific content for that notification type.

**Step 5: Commit**

```bash
git add resources/views/emails/
git commit -m "Add email notification templates for low stock, orders, and test"
```

---

## Task 8: Update NotificationService with Email Sending

**Files:**
- Modify: `app/Services/NotificationService.php`

**Step 1: Add sendEmailNotification method**

Add the private method to NotificationService that handles email sending with plugin hooks and user preferences.

**Step 2: Update createLowStockNotification**

Add call to `self::sendEmailNotification()` after creating in-app notification.

**Step 3: Update createOrderStatusNotification**

Add call to `self::sendEmailNotification()` after creating in-app notification.

**Step 4: Update createOrderApprovalNotification**

Add call to `self::sendEmailNotification()` after creating in-app notification.

**Step 5: Commit**

```bash
git add app/Services/NotificationService.php
git commit -m "Extend NotificationService to send email notifications with plugin hooks"
```

---

## Task 9: Create SettingsController

**Files:**
- Create: `app/Http/Controllers/SettingsController.php`

**Step 1: Create controller**

```bash
php artisan make:controller SettingsController
```

**Step 2: Add index method**

```php
public function index()
{
    if (!auth()->user()->isAdmin()) {
        abort(403, 'Only organization admins can access settings');
    }

    return Inertia::render('Settings/Index', [
        'emailConfig' => SettingsService::getEmailConfig(),
        'userPreferences' => auth()->user()->notification_preferences ?? [],
    ]);
}
```

**Step 3: Add updateEmail method**

Add validation and saving logic for all email provider settings.

**Step 4: Add testEmail method**

Add test email sending functionality.

**Step 5: Commit**

```bash
git add app/Http/Controllers/SettingsController.php
git commit -m "Add SettingsController for email configuration management"
```

---

## Task 10: Add Settings Routes

**Files:**
- Modify: `routes/web.php`

**Step 1: Add settings routes**

```php
Route::middleware(['auth'])->group(function () {
    Route::get('/settings', [SettingsController::class, 'index'])
        ->name('settings.index');

    Route::post('/settings/email', [SettingsController::class, 'updateEmail'])
        ->name('settings.email.update');

    Route::post('/settings/email/test', [SettingsController::class, 'testEmail'])
        ->name('settings.email.test');
});
```

**Step 2: Commit**

```bash
git add routes/web.php
git commit -m "Add settings routes for email configuration"
```

---

## Task 11: Create Settings Vue Page

**Files:**
- Create: `resources/js/Pages/Settings/Index.vue`
- Create: `resources/js/Pages/Settings/Partials/EmailSettings.vue`
- Create: `resources/js/Pages/Settings/Partials/NotificationPreferences.vue`

**Step 1: Create Settings directory**

```bash
mkdir -p resources/js/Pages/Settings/Partials
```

**Step 2: Create Settings/Index.vue**

Main settings page with tab navigation (Email Configuration, Email Preferences tabs).

**Step 3: Create EmailSettings.vue**

Form for configuring email provider with conditional sections for SMTP, Mailgun, SendGrid, and test email functionality.

**Step 4: Create NotificationPreferences.vue**

Toggle switches for user email preferences (master toggle + per-category toggles).

**Step 5: Commit**

```bash
git add resources/js/Pages/Settings/
git commit -m "Add Settings UI with email configuration and preferences"
```

---

## Task 12: Add Settings Link to Navigation

**Files:**
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue` (or wherever nav is)

**Step 1: Add Settings link**

Add a navigation link to `/settings` in the main navigation menu (for admins).

**Step 2: Commit**

```bash
git add resources/js/Layouts/
git commit -m "Add Settings link to main navigation"
```

---

## Task 13: Update Todo and Documentation

**Files:**
- Modify: `todo.txt`
- Create: `docs/features/email-notifications.md`

**Step 1: Update todo.txt**

Change email notification status from "INCOMPLETE" to "COMPLETE ✓" with feature list.

**Step 2: Create user documentation**

Create comprehensive user-facing documentation for email notifications feature.

**Step 3: Commit**

```bash
git add todo.txt docs/features/email-notifications.md
git commit -m "Update documentation for email notifications feature"
```

---

## Task 14: Manual Testing

**Testing Checklist:**

**Settings UI:**
- [ ] Settings page loads for admin users
- [ ] Settings page forbidden for non-admin users
- [ ] Email provider dropdown shows all 4 options
- [ ] SMTP form shows/hides based on provider selection
- [ ] Mailgun form shows/hides based on provider selection
- [ ] SendGrid form shows/hides based on provider selection
- [ ] Save button works and shows success message
- [ ] Test email sends successfully
- [ ] Test email received with correct formatting

**Email Notifications:**
- [ ] Low stock alert sends email when product stock reaches minimum
- [ ] Order status change sends email to order creator
- [ ] Order approval sends email to order creator
- [ ] Order rejection sends email to order creator
- [ ] Email preferences toggle works (master switch)
- [ ] Category-specific toggles work (low stock, orders, approvals)
- [ ] Users who disabled emails don't receive them

**Multi-Tenant:**
- [ ] Each organization has separate settings
- [ ] Email logs are organization-scoped
- [ ] Settings from one org don't affect another

---

## Success Criteria

✅ Settings and email_logs tables created
✅ Setting model with organization relationship
✅ SettingsService manages configuration with caching
✅ EmailLogger tracks sent/failed emails
✅ Four Mailable classes created
✅ Email templates created with responsive design
✅ NotificationService sends emails with plugin hooks
✅ SettingsController handles configuration
✅ Settings routes added
✅ Settings UI with tabs (Email Config, Preferences)
✅ Navigation includes Settings link
✅ Documentation updated
✅ All manual tests pass

---

## Estimated Time

- Task 1: 10 min (Migrations)
- Task 2: 5 min (Setting model)
- Task 3: 10 min (SettingsService)
- Task 4: 5 min (EmailLogger)
- Task 5: 15 min (Mailables)
- Task 6: 10 min (Email layout)
- Task 7: 20 min (Email templates)
- Task 8: 20 min (Update NotificationService)
- Task 9: 15 min (SettingsController)
- Task 10: 5 min (Routes)
- Task 11: 30 min (Vue components)
- Task 12: 5 min (Navigation link)
- Task 13: 10 min (Documentation)
- Task 14: 30 min (Testing)

**Total: ~190 minutes** (3 hours 10 minutes)

---

## Notes

- No breaking changes to existing functionality
- Plugin hooks follow existing patterns in the codebase
- Multi-tenant architecture maintained throughout
- Encrypted settings use Laravel's encrypt/decrypt helpers
- Email templates tested across major email clients
- Design document: `docs/plans/2026-02-05-email-notifications-design.md`
