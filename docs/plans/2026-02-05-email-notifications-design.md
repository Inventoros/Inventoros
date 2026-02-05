# Email Notifications System Design

**Date:** 2026-02-05
**Status:** Approved
**Priority:** High

## Overview

Implement a comprehensive email notification system with configurable email providers (SMTP, PHP Mail, Mailgun, SendGrid) that can be managed within the application by organization admins. Extend existing in-app notifications to also send emails based on user preferences.

## Goals

1. Enable email notifications for critical events (low stock, order status, approvals)
2. Allow organization admins to configure email providers through the UI
3. Support multiple email providers (SMTP, PHP Mail, Mailgun, SendGrid)
4. Respect per-user email preferences
5. Provide plugin hooks for extensibility
6. Maintain multi-tenant architecture (organization-scoped settings)

---

## Architecture Decisions

### Storage Strategy
- **Organization-based settings** stored in database
- Each organization configures their own email provider
- Settings encrypted for sensitive data (passwords, API keys)

### Supported Providers
- SMTP (Gmail, Office365, custom servers)
- PHP Mail (simple server mail function)
- Mailgun (API-based)
- SendGrid (API-based)

### Permissions
- Only organization admins can configure email settings
- Uses existing `isAdmin()` check on User model

### UI Structure
- Dedicated `/settings` page with tabs
- Email configuration in "Email" tab
- Extensible for future settings (General, Notifications, API Keys)

### Email Events
1. Low Stock Alerts - When product stock reaches minimum threshold
2. Order Status Changed - When order status updates
3. Order Approvals - When order is approved or rejected

### User Preferences
- Per-user opt-out controls
- Extends existing `notification_preferences` JSON column
- Email toggles per notification category

---

## Database Schema

### Settings Table

```sql
CREATE TABLE settings (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NOT NULL,
    `key` VARCHAR(255) NOT NULL,
    value TEXT NULL,
    encrypted BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    UNIQUE KEY unique_org_key (organization_id, `key`),
    INDEX idx_org_key (organization_id, `key`)
);
```

### Settings Keys Structure

**Email Provider Settings:**
- `email.provider` - smtp|phpmail|mailgun|sendgrid
- `email.from_address` - Default sender email
- `email.from_name` - Default sender name

**SMTP Settings:**
- `email.smtp.host` - SMTP server hostname
- `email.smtp.port` - SMTP server port (25, 465, 587)
- `email.smtp.username` - SMTP username
- `email.smtp.password` - SMTP password (encrypted)
- `email.smtp.encryption` - tls|ssl|none

**Mailgun Settings:**
- `email.mailgun.domain` - Mailgun domain
- `email.mailgun.secret` - Mailgun API secret (encrypted)

**SendGrid Settings:**
- `email.sendgrid.api_key` - SendGrid API key (encrypted)

### User Preferences Extension

Extend existing `notification_preferences` JSON column on `users` table:

```json
{
  "low_stock_alerts": true,
  "order_notifications": true,
  "system_notifications": true,
  "email_enabled": true,
  "email_low_stock": true,
  "email_orders": true,
  "email_approvals": true
}
```

### Email Logs Table

```sql
CREATE TABLE email_logs (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    type VARCHAR(255) NOT NULL,
    status ENUM('sent', 'failed') NOT NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP NULL,

    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_org_created (organization_id, created_at)
);
```

---

## Backend Implementation

### Settings Service

**File:** `app/Services/SettingsService.php`

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

### Settings Controller

**File:** `app/Http/Controllers/SettingsController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Services\SettingsService;
use App\Mail\TestEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class SettingsController extends Controller
{
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

    public function updateEmail(Request $request)
    {
        if (!auth()->user()->isAdmin()) {
            abort(403);
        }

        $validated = $request->validate([
            'provider' => 'required|in:smtp,phpmail,mailgun,sendgrid',
            'from_address' => 'required|email',
            'from_name' => 'required|string|max:255',

            'smtp.host' => 'required_if:provider,smtp|nullable|string',
            'smtp.port' => 'required_if:provider,smtp|nullable|integer|between:1,65535',
            'smtp.username' => 'nullable|string',
            'smtp.password' => 'nullable|string',
            'smtp.encryption' => 'required_if:provider,smtp|nullable|in:tls,ssl,none',

            'mailgun.domain' => 'required_if:provider,mailgun|nullable|string',
            'mailgun.secret' => 'required_if:provider,mailgun|nullable|string',

            'sendgrid.api_key' => 'required_if:provider,sendgrid|nullable|string',
        ]);

        // Save general settings
        SettingsService::set('email.provider', $validated['provider']);
        SettingsService::set('email.from_address', $validated['from_address']);
        SettingsService::set('email.from_name', $validated['from_name']);

        // Save provider-specific settings
        if ($validated['provider'] === 'smtp') {
            SettingsService::set('email.smtp.host', $validated['smtp']['host']);
            SettingsService::set('email.smtp.port', $validated['smtp']['port']);
            SettingsService::set('email.smtp.username', $validated['smtp']['username']);
            SettingsService::set('email.smtp.password', $validated['smtp']['password'], true);
            SettingsService::set('email.smtp.encryption', $validated['smtp']['encryption']);
        } elseif ($validated['provider'] === 'mailgun') {
            SettingsService::set('email.mailgun.domain', $validated['mailgun']['domain']);
            SettingsService::set('email.mailgun.secret', $validated['mailgun']['secret'], true);
        } elseif ($validated['provider'] === 'sendgrid') {
            SettingsService::set('email.sendgrid.api_key', $validated['sendgrid']['api_key'], true);
        }

        return back()->with('success', 'Email settings saved successfully');
    }

    public function testEmail(Request $request)
    {
        if (!auth()->user()->isAdmin()) {
            abort(403);
        }

        $request->validate([
            'test_email' => 'required|email'
        ]);

        try {
            SettingsService::applyEmailConfig();

            Mail::to($request->test_email)->send(new TestEmail([
                'organization' => auth()->user()->organization->name,
                'tested_by' => auth()->user()->name,
            ]));

            return back()->with('success', 'Test email sent successfully! Check your inbox.');

        } catch (\Exception $e) {
            \Log::error('Test email failed', [
                'error' => $e->getMessage(),
                'organization_id' => auth()->user()->organization_id,
            ]);

            return back()->with('error', 'Failed to send test email: ' . $e->getMessage());
        }
    }
}
```

### Extended NotificationService

**File:** `app/Services/NotificationService.php` (additions)

```php
/**
 * Send email notification to user.
 */
private static function sendEmailNotification(User $user, string $type, array $data): void
{
    // Check if user has email enabled
    $preferences = $user->notification_preferences ?? [];
    if (!($preferences['email_enabled'] ?? true)) {
        return;
    }

    // Check specific email preference
    $emailPreferenceMap = [
        'low_stock' => 'email_low_stock',
        'out_of_stock' => 'email_low_stock',
        'order_created' => 'email_orders',
        'order_status_updated' => 'email_orders',
        'order_approved' => 'email_approvals',
        'order_rejected' => 'email_approvals',
    ];

    $prefKey = $emailPreferenceMap[$type] ?? null;
    if ($prefKey && !($preferences[$prefKey] ?? true)) {
        return;
    }

    // Apply organization's email configuration
    SettingsService::applyEmailConfig();

    // HOOK: Allow plugins to modify email data
    $data = apply_filters('email_notification_data', $data, $type, $user);

    // HOOK: Allow plugins to prevent sending
    if (!apply_filters('should_send_email', true, $type, $user, $data)) {
        return;
    }

    try {
        // HOOK: Allow plugins to provide custom mailable
        $mailableClass = apply_filters('email_mailable_class', null, $type, $data);

        if ($mailableClass) {
            Mail::to($user->email)->send(new $mailableClass($data));
        } else {
            // Use default email types
            switch ($type) {
                case 'low_stock':
                case 'out_of_stock':
                    Mail::to($user->email)->send(new \App\Mail\LowStockEmail($data));
                    break;
                case 'order_status_updated':
                    Mail::to($user->email)->send(new \App\Mail\OrderStatusEmail($data));
                    break;
                case 'order_approved':
                case 'order_rejected':
                    Mail::to($user->email)->send(new \App\Mail\OrderApprovalEmail($data));
                    break;
            }
        }

        // HOOK: After email sent
        do_action('email_notification_sent', $type, $user, $data);

        // Log success
        EmailLogger::logSent($type, $user, $data);

    } catch (\Exception $e) {
        // HOOK: Email failed
        do_action('email_notification_failed', $type, $user, $data, $e);

        // Log failure
        EmailLogger::logFailed($type, $user, $e);

        \Log::error('Failed to send email notification', [
            'user_id' => $user->id,
            'type' => $type,
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Update existing notification methods to also send emails.
 */
public static function createLowStockNotification(Product $product): void
{
    // Get all users in the organization with manage_stock permission
    $users = User::where('organization_id', $product->organization_id)
        ->whereHas('roles', function ($query) {
            $query->whereJsonContains('permissions', 'manage_stock');
        })
        ->get();

    // HOOK: Allow plugins to modify recipients
    $users = apply_filters('low_stock_notification_recipients', $users, $product);

    foreach ($users as $user) {
        if (!self::shouldNotifyUser($user, 'low_stock')) {
            continue;
        }

        // Create in-app notification (existing)
        Notification::create([
            'organization_id' => $product->organization_id,
            'user_id' => $user->id,
            'type' => 'low_stock',
            'title' => 'Low Stock Alert',
            'message' => "Product '{$product->name}' (SKU: {$product->sku}) is running low. Current stock: {$product->stock}, Minimum: {$product->min_stock}",
            'data' => [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'current_stock' => $product->stock,
                'min_stock' => $product->min_stock,
            ],
            'action_url' => route('products.show', $product->id),
            'priority' => $product->stock == 0 ? 'urgent' : 'high',
        ]);

        // Send email (NEW)
        self::sendEmailNotification($user, 'low_stock', [
            'product' => $product,
            'notification_url' => route('products.show', $product->id),
        ]);
    }
}

// Apply same pattern to other notification methods:
// - createOrderStatusNotification
// - createOrderApprovalNotification
```

### Email Logger Service

**File:** `app/Services/EmailLogger.php`

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

### Mailable Classes

**File:** `app/Mail/LowStockEmail.php`

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

**File:** `app/Mail/OrderStatusEmail.php`

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

**File:** `app/Mail/OrderApprovalEmail.php`

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

**File:** `app/Mail/TestEmail.php`

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

---

## Email Templates

### Base Layout

**File:** `resources/views/emails/layout.blade.php`

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Notification' }}</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; background-color: #f3f4f6;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6; padding: 40px 0;">
        <tr>
            <td align="center">
                <!-- Email Container -->
                <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden;">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 40px 30px; text-align: center;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 28px; font-weight: 600; letter-spacing: -0.5px;">
                                Inventoros
                            </h1>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px 30px;">
                            {{-- HOOK: Before content --}}
                            {!! apply_filters('email_before_content', '', $emailType ?? '', $data ?? []) !!}

                            @yield('content')

                            {{-- HOOK: After content --}}
                            {!! apply_filters('email_after_content', '', $emailType ?? '', $data ?? []) !!}
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding: 30px; background-color: #f9fafb; border-top: 1px solid #e5e7eb;">
                            <p style="margin: 0 0 10px 0; color: #6b7280; font-size: 14px; line-height: 1.5; text-align: center;">
                                You're receiving this because you have email notifications enabled.
                            </p>
                            <p style="margin: 0; text-align: center;">
                                <a href="{{ route('settings.index') }}" style="color: #667eea; text-decoration: none; font-size: 14px;">
                                    Manage Email Preferences
                                </a>
                            </p>
                        </td>
                    </tr>
                </table>

                <!-- Footer Text -->
                <table width="600" cellpadding="0" cellspacing="0" style="margin-top: 20px;">
                    <tr>
                        <td style="text-align: center; color: #9ca3af; font-size: 12px;">
                            © {{ date('Y') }} Inventoros. All rights reserved.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
```

### Low Stock Alert Template

**File:** `resources/views/emails/low-stock-alert.blade.php`

```blade
@extends('emails.layout')

@section('content')
    <h2 style="margin: 0 0 20px 0; color: #111827; font-size: 22px; font-weight: 600;">
        ⚠️ Low Stock Alert
    </h2>

    <p style="margin: 0 0 20px 0; color: #374151; font-size: 16px; line-height: 1.6;">
        The following product is running low on stock and needs your attention:
    </p>

    <!-- Product Info Box -->
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #fef3c7; border-left: 4px solid #f59e0b; border-radius: 6px; margin: 20px 0;">
        <tr>
            <td style="padding: 20px;">
                <table width="100%">
                    <tr>
                        <td>
                            <strong style="color: #92400e; font-size: 18px; display: block; margin-bottom: 8px;">
                                {{ $product->name }}
                            </strong>
                            <span style="color: #78350f; font-size: 14px;">
                                SKU: {{ $product->sku }}
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding-top: 15px;">
                            <table width="100%">
                                <tr>
                                    <td width="50%">
                                        <span style="color: #78350f; font-size: 14px; display: block; margin-bottom: 5px;">
                                            Current Stock
                                        </span>
                                        <strong style="color: #dc2626; font-size: 24px;">
                                            {{ $product->stock }}
                                        </strong>
                                    </td>
                                    <td width="50%">
                                        <span style="color: #78350f; font-size: 14px; display: block; margin-bottom: 5px;">
                                            Minimum Stock
                                        </span>
                                        <strong style="color: #92400e; font-size: 24px;">
                                            {{ $product->min_stock }}
                                        </strong>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- HOOK: Additional actions --}}
    {!! apply_filters('email_additional_actions', '', 'low_stock', $product) !!}

    <!-- Action Button -->
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-top: 30px;">
        <tr>
            <td align="center">
                <a href="{{ $notification_url }}" style="display: inline-block; padding: 14px 32px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 16px;">
                    View Product Details
                </a>
            </td>
        </tr>
    </table>

    <p style="margin: 30px 0 0 0; color: #6b7280; font-size: 14px; line-height: 1.5; padding-top: 20px; border-top: 1px solid #e5e7eb;">
        💡 <strong>Tip:</strong> Consider creating a purchase order to restock this product.
    </p>
@endsection
```

### Order Status Changed Template

**File:** `resources/views/emails/order-status.blade.php`

```blade
@extends('emails.layout')

@section('content')
    <h2 style="margin: 0 0 20px 0; color: #111827; font-size: 22px; font-weight: 600;">
        📦 Order Status Updated
    </h2>

    <p style="margin: 0 0 20px 0; color: #374151; font-size: 16px; line-height: 1.6;">
        Order <strong>#{{ $order->order_number }}</strong> status has been updated.
    </p>

    <!-- Status Change Box -->
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6; border-radius: 6px; margin: 20px 0;">
        <tr>
            <td style="padding: 25px;">
                <table width="100%">
                    <tr>
                        <td style="padding-bottom: 15px;">
                            <span style="color: #6b7280; font-size: 14px; display: block; margin-bottom: 5px;">
                                Previous Status
                            </span>
                            <span style="display: inline-block; padding: 6px 12px; background-color: #e5e7eb; color: #374151; border-radius: 4px; font-size: 14px; font-weight: 500;">
                                {{ ucfirst($old_status) }}
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td style="text-align: center; padding: 10px 0;">
                            <span style="color: #9ca3af; font-size: 20px;">↓</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding-top: 15px;">
                            <span style="color: #6b7280; font-size: 14px; display: block; margin-bottom: 5px;">
                                New Status
                            </span>
                            <span style="display: inline-block; padding: 6px 12px; background-color: #d1fae5; color: #065f46; border-radius: 4px; font-size: 14px; font-weight: 600;">
                                {{ ucfirst($order->status) }}
                            </span>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <!-- Order Details -->
    <table width="100%" cellpadding="0" cellspacing="0" style="margin: 20px 0; border-top: 1px solid #e5e7eb; padding-top: 20px;">
        <tr>
            <td width="50%" style="padding: 8px 0;">
                <span style="color: #6b7280; font-size: 14px;">Customer:</span>
            </td>
            <td width="50%" style="padding: 8px 0; text-align: right;">
                <strong style="color: #111827; font-size: 14px;">{{ $order->customer_name }}</strong>
            </td>
        </tr>
        <tr>
            <td width="50%" style="padding: 8px 0;">
                <span style="color: #6b7280; font-size: 14px;">Order Total:</span>
            </td>
            <td width="50%" style="padding: 8px 0; text-align: right;">
                <strong style="color: #111827; font-size: 14px;">${{ number_format($order->total, 2) }}</strong>
            </td>
        </tr>
    </table>

    {{-- HOOK: Additional actions --}}
    {!! apply_filters('email_additional_actions', '', 'order_status', $order) !!}

    <!-- Action Button -->
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-top: 30px;">
        <tr>
            <td align="center">
                <a href="{{ $notification_url }}" style="display: inline-block; padding: 14px 32px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 16px;">
                    View Order Details
                </a>
            </td>
        </tr>
    </table>
@endsection
```

### Order Approval Template

**File:** `resources/views/emails/order-approval.blade.php`

```blade
@extends('emails.layout')

@section('content')
    @php
        $isApproved = $order->approval_status === 'approved';
        $icon = $isApproved ? '✅' : '❌';
        $statusColor = $isApproved ? '#10b981' : '#ef4444';
        $statusBgColor = $isApproved ? '#d1fae5' : '#fee2e2';
    @endphp

    <h2 style="margin: 0 0 20px 0; color: #111827; font-size: 22px; font-weight: 600;">
        {{ $icon }} Order {{ ucfirst($order->approval_status) }}
    </h2>

    <p style="margin: 0 0 20px 0; color: #374151; font-size: 16px; line-height: 1.6;">
        Your order <strong>#{{ $order->order_number }}</strong> has been
        <strong style="color: {{ $statusColor }};">{{ $order->approval_status }}</strong>
        by {{ $order->approver->name }}.
    </p>

    <!-- Status Box -->
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: {{ $statusBgColor }}; border-left: 4px solid {{ $statusColor }}; border-radius: 6px; margin: 20px 0;">
        <tr>
            <td style="padding: 20px;">
                <strong style="color: {{ $statusColor }}; font-size: 16px; display: block; margin-bottom: 10px;">
                    Status: {{ ucfirst($order->approval_status) }}
                </strong>

                @if($order->approval_notes)
                    <p style="margin: 10px 0 0 0; color: #374151; font-size: 14px; line-height: 1.5;">
                        <strong>Notes:</strong> {{ $order->approval_notes }}
                    </p>
                @endif
            </td>
        </tr>
    </table>

    <!-- Order Details -->
    <table width="100%" cellpadding="0" cellspacing="0" style="margin: 20px 0; border-top: 1px solid #e5e7eb; padding-top: 20px;">
        <tr>
            <td width="50%" style="padding: 8px 0;">
                <span style="color: #6b7280; font-size: 14px;">Order Number:</span>
            </td>
            <td width="50%" style="padding: 8px 0; text-align: right;">
                <strong style="color: #111827; font-size: 14px;">#{{ $order->order_number }}</strong>
            </td>
        </tr>
        <tr>
            <td width="50%" style="padding: 8px 0;">
                <span style="color: #6b7280; font-size: 14px;">Customer:</span>
            </td>
            <td width="50%" style="padding: 8px 0; text-align: right;">
                <strong style="color: #111827; font-size: 14px;">{{ $order->customer_name }}</strong>
            </td>
        </tr>
        <tr>
            <td width="50%" style="padding: 8px 0;">
                <span style="color: #6b7280; font-size: 14px;">Total:</span>
            </td>
            <td width="50%" style="padding: 8px 0; text-align: right;">
                <strong style="color: #111827; font-size: 14px;">${{ number_format($order->total, 2) }}</strong>
            </td>
        </tr>
        <tr>
            <td width="50%" style="padding: 8px 0;">
                <span style="color: #6b7280; font-size: 14px;">{{ $isApproved ? 'Approved' : 'Reviewed' }} By:</span>
            </td>
            <td width="50%" style="padding: 8px 0; text-align: right;">
                <strong style="color: #111827; font-size: 14px;">{{ $order->approver->name }}</strong>
            </td>
        </tr>
    </table>

    {{-- HOOK: Additional actions --}}
    {!! apply_filters('email_additional_actions', '', 'order_approval', $order) !!}

    <!-- Action Button -->
    <table width="100%" cellpadding="0" cellspacing="0" style="margin-top: 30px;">
        <tr>
            <td align="center">
                <a href="{{ $notification_url }}" style="display: inline-block; padding: 14px 32px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 16px;">
                    View Order Details
                </a>
            </td>
        </tr>
    </table>

    @if($isApproved)
        <p style="margin: 30px 0 0 0; color: #6b7280; font-size: 14px; line-height: 1.5; padding-top: 20px; border-top: 1px solid #e5e7eb;">
            🎉 Your order has been approved and will be processed shortly.
        </p>
    @endif
@endsection
```

### Test Email Template

**File:** `resources/views/emails/test-email.blade.php`

```blade
@extends('emails.layout')

@section('content')
    <h2 style="margin: 0 0 20px 0; color: #111827; font-size: 22px; font-weight: 600;">
        🎉 Test Email Successful!
    </h2>

    <p style="margin: 0 0 15px 0; color: #374151; font-size: 16px; line-height: 1.6;">
        Great news! Your email configuration is working correctly.
    </p>

    <!-- Success Box -->
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #d1fae5; border-left: 4px solid #10b981; border-radius: 6px; margin: 20px 0;">
        <tr>
            <td style="padding: 20px;">
                <strong style="color: #065f46; font-size: 16px; display: block; margin-bottom: 10px;">
                    ✓ Email Configuration Test Passed
                </strong>
                <p style="margin: 0; color: #047857; font-size: 14px;">
                    Your organization's email settings are properly configured and ready to send notifications.
                </p>
            </td>
        </tr>
    </table>

    <!-- Test Details -->
    <table width="100%" cellpadding="0" cellspacing="0" style="margin: 20px 0; border-top: 1px solid #e5e7eb; padding-top: 20px;">
        <tr>
            <td width="40%" style="padding: 8px 0;">
                <span style="color: #6b7280; font-size: 14px;">Organization:</span>
            </td>
            <td width="60%" style="padding: 8px 0;">
                <strong style="color: #111827; font-size: 14px;">{{ $organization }}</strong>
            </td>
        </tr>
        <tr>
            <td width="40%" style="padding: 8px 0;">
                <span style="color: #6b7280; font-size: 14px;">Tested By:</span>
            </td>
            <td width="60%" style="padding: 8px 0;">
                <strong style="color: #111827; font-size: 14px;">{{ $tested_by }}</strong>
            </td>
        </tr>
        <tr>
            <td width="40%" style="padding: 8px 0;">
                <span style="color: #6b7280; font-size: 14px;">Test Time:</span>
            </td>
            <td width="60%" style="padding: 8px 0;">
                <strong style="color: #111827; font-size: 14px;">{{ now()->format('M d, Y h:i A') }}</strong>
            </td>
        </tr>
    </table>

    <p style="margin: 30px 0 0 0; color: #6b7280; font-size: 14px; line-height: 1.5; padding-top: 20px; border-top: 1px solid #e5e7eb;">
        You can now close this email. Your system is ready to send email notifications to your users.
    </p>
@endsection
```

---

## Plugin Hooks

### Available Email Hooks

**Filter Hooks:**
- `email_notification_data` - Modify email data before sending
- `should_send_email` - Prevent email from being sent
- `email_mailable_class` - Provide custom Mailable class for custom notification types
- `{type}_notification_recipients` - Modify recipient list (e.g., `low_stock_notification_recipients`)
- `email_before_content` - Add content before email body
- `email_after_content` - Add content after email body
- `email_additional_actions` - Add custom action buttons

**Action Hooks:**
- `email_notification_sent` - Triggered after email sent successfully
- `email_notification_failed` - Triggered when email fails to send

### Example Plugin Usage

```php
<?php

namespace Plugins\EmailExtensions;

class EmailExtensionsPlugin
{
    public function register()
    {
        // Add custom email type
        add_filter('email_mailable_class', [$this, 'handleCustomEmail'], 10, 3);

        // Modify email data
        add_filter('email_notification_data', [$this, 'addCustomData'], 10, 3);

        // Add extra recipients
        add_filter('low_stock_notification_recipients', [$this, 'addSupplierNotification'], 10, 2);

        // Track sent emails
        add_action('email_notification_sent', [$this, 'logToExternalService'], 10, 3);

        // Add custom content
        add_filter('email_additional_actions', [$this, 'addCustomButton'], 10, 3);
    }

    public function handleCustomEmail($mailableClass, $type, $data)
    {
        if ($type === 'supplier_low_stock') {
            return \Plugins\EmailExtensions\Mail\SupplierLowStockEmail::class;
        }
        return $mailableClass;
    }

    public function addCustomData($data, $type, $user)
    {
        // Add warehouse location
        if ($type === 'low_stock' && isset($data['product'])) {
            $data['warehouse_info'] = $this->getWarehouseInfo($data['product']);
        }
        return $data;
    }

    public function addSupplierNotification($users, $product)
    {
        // Add supplier contact to notification list
        if ($product->supplier && $product->supplier->email) {
            $supplierUser = User::where('email', $product->supplier->email)->first();
            if ($supplierUser) {
                $users->push($supplierUser);
            }
        }
        return $users;
    }

    public function logToExternalService($type, $user, $data)
    {
        // Send to analytics service, CRM, etc.
        \Log::info('Email sent', [
            'type' => $type,
            'user_id' => $user->id,
            'timestamp' => now(),
        ]);
    }

    public function addCustomButton($html, $type, $data)
    {
        if ($type === 'low_stock') {
            return '<a href="' . route('purchase-orders.create') . '" style="...">Create Purchase Order</a>';
        }
        return $html;
    }
}
```

---

## Frontend Implementation

### Routes

**File:** `routes/web.php`

```php
Route::middleware(['auth'])->group(function () {
    // Settings routes
    Route::get('/settings', [SettingsController::class, 'index'])
        ->name('settings.index');

    Route::post('/settings/email', [SettingsController::class, 'updateEmail'])
        ->name('settings.email.update');

    Route::post('/settings/email/test', [SettingsController::class, 'testEmail'])
        ->name('settings.email.test');

    Route::post('/settings/preferences', [SettingsController::class, 'updatePreferences'])
        ->name('settings.preferences.update');
});
```

### Settings Page Structure

```
resources/js/Pages/Settings/
├── Index.vue (main page with tab navigation)
├── Partials/
│   ├── EmailSettings.vue (email provider configuration)
│   ├── NotificationPreferences.vue (user email toggles)
│   └── GeneralSettings.vue (future: organization details)
```

### Settings Index Page

**File:** `resources/js/Pages/Settings/Index.vue`

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmailSettings from './Partials/EmailSettings.vue';
import NotificationPreferences from './Partials/NotificationPreferences.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    emailConfig: Object,
    userPreferences: Object,
});

const activeTab = ref('email');
</script>

<template>
    <Head title="Settings" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-2xl text-gray-900 dark:text-gray-100">
                Settings
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Tab Navigation -->
                <div class="mb-6 border-b border-gray-200 dark:border-dark-border">
                    <nav class="-mb-px flex space-x-8">
                        <button
                            @click="activeTab = 'email'"
                            :class="[
                                activeTab === 'email'
                                    ? 'border-primary-400 text-primary-400'
                                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
                                'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition'
                            ]"
                        >
                            Email Configuration
                        </button>

                        <button
                            @click="activeTab = 'preferences'"
                            :class="[
                                activeTab === 'preferences'
                                    ? 'border-primary-400 text-primary-400'
                                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
                                'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition'
                            ]"
                        >
                            Email Preferences
                        </button>
                    </nav>
                </div>

                <!-- Tab Content -->
                <div v-if="activeTab === 'email'">
                    <EmailSettings :email-config="emailConfig" />
                </div>

                <div v-if="activeTab === 'preferences'">
                    <NotificationPreferences :preferences="userPreferences" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

### Email Settings Component

**File:** `resources/js/Pages/Settings/Partials/EmailSettings.vue`

```vue
<script setup>
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    emailConfig: Object,
});

const form = useForm({
    provider: props.emailConfig.provider || 'smtp',
    from_address: props.emailConfig.from_address || '',
    from_name: props.emailConfig.from_name || '',
    smtp: {
        host: props.emailConfig.smtp?.host || '',
        port: props.emailConfig.smtp?.port || 587,
        username: props.emailConfig.smtp?.username || '',
        password: props.emailConfig.smtp?.password || '',
        encryption: props.emailConfig.smtp?.encryption || 'tls',
    },
    mailgun: {
        domain: props.emailConfig.mailgun?.domain || '',
        secret: props.emailConfig.mailgun?.secret || '',
    },
    sendgrid: {
        api_key: props.emailConfig.sendgrid?.api_key || '',
    },
});

const testEmailAddress = ref('');
const sendingTest = ref(false);

const submit = () => {
    form.post(route('settings.email.update'));
};

const sendTestEmail = () => {
    if (!testEmailAddress.value) return;

    sendingTest.value = true;

    axios.post(route('settings.email.test'), {
        test_email: testEmailAddress.value
    }).then(() => {
        alert('Test email sent! Check your inbox.');
    }).catch(error => {
        alert('Failed to send test email: ' + error.response.data.message);
    }).finally(() => {
        sendingTest.value = false;
    });
};
</script>

<template>
    <div class="bg-white dark:bg-dark-card shadow sm:rounded-lg">
        <div class="px-6 py-5 border-b border-gray-200 dark:border-dark-border">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                Email Configuration
            </h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Configure how your organization sends email notifications.
                Only organization admins can change these settings.
            </p>
        </div>

        <form @submit.prevent="submit" class="px-6 py-5 space-y-6">
            <!-- Email Provider -->
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Email Provider
                </label>
                <select
                    v-model="form.provider"
                    class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100"
                >
                    <option value="smtp">SMTP</option>
                    <option value="phpmail">PHP Mail</option>
                    <option value="mailgun">Mailgun</option>
                    <option value="sendgrid">SendGrid</option>
                </select>
            </div>

            <!-- From Address -->
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    From Email Address
                </label>
                <input
                    v-model="form.from_address"
                    type="email"
                    required
                    class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100"
                    placeholder="noreply@yourcompany.com"
                />
            </div>

            <!-- From Name -->
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    From Name
                </label>
                <input
                    v-model="form.from_name"
                    type="text"
                    required
                    class="block w-full rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100"
                    placeholder="Your Company Name"
                />
            </div>

            <!-- SMTP Settings -->
            <div v-if="form.provider === 'smtp'" class="space-y-4 p-4 bg-gray-50 dark:bg-dark-bg rounded-lg">
                <h4 class="font-medium text-gray-900 dark:text-gray-100">SMTP Configuration</h4>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Host
                        </label>
                        <input
                            v-model="form.smtp.host"
                            type="text"
                            class="block w-full rounded-md bg-white dark:bg-dark-card border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100"
                            placeholder="smtp.gmail.com"
                        />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Port
                        </label>
                        <input
                            v-model.number="form.smtp.port"
                            type="number"
                            class="block w-full rounded-md bg-white dark:bg-dark-card border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100"
                            placeholder="587"
                        />
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Username
                    </label>
                    <input
                        v-model="form.smtp.username"
                        type="text"
                        class="block w-full rounded-md bg-white dark:bg-dark-card border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100"
                    />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Password
                    </label>
                    <input
                        v-model="form.smtp.password"
                        type="password"
                        class="block w-full rounded-md bg-white dark:bg-dark-card border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100"
                        placeholder="Leave blank to keep current password"
                    />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Encryption
                    </label>
                    <select
                        v-model="form.smtp.encryption"
                        class="block w-full rounded-md bg-white dark:bg-dark-card border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100"
                    >
                        <option value="tls">TLS</option>
                        <option value="ssl">SSL</option>
                        <option value="none">None</option>
                    </select>
                </div>
            </div>

            <!-- Mailgun Settings -->
            <div v-if="form.provider === 'mailgun'" class="space-y-4 p-4 bg-gray-50 dark:bg-dark-bg rounded-lg">
                <h4 class="font-medium text-gray-900 dark:text-gray-100">Mailgun Configuration</h4>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Domain
                    </label>
                    <input
                        v-model="form.mailgun.domain"
                        type="text"
                        class="block w-full rounded-md bg-white dark:bg-dark-card border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100"
                        placeholder="mg.yourcompany.com"
                    />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        API Secret
                    </label>
                    <input
                        v-model="form.mailgun.secret"
                        type="password"
                        class="block w-full rounded-md bg-white dark:bg-dark-card border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100"
                        placeholder="key-..."
                    />
                </div>
            </div>

            <!-- SendGrid Settings -->
            <div v-if="form.provider === 'sendgrid'" class="space-y-4 p-4 bg-gray-50 dark:bg-dark-bg rounded-lg">
                <h4 class="font-medium text-gray-900 dark:text-gray-100">SendGrid Configuration</h4>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        API Key
                    </label>
                    <input
                        v-model="form.sendgrid.api_key"
                        type="password"
                        class="block w-full rounded-md bg-white dark:bg-dark-card border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100"
                        placeholder="SG...."
                    />
                </div>
            </div>

            <!-- Test Email -->
            <div class="border-t border-gray-200 dark:border-dark-border pt-6">
                <h4 class="font-medium text-gray-900 dark:text-gray-100 mb-3">Test Email Configuration</h4>
                <div class="flex gap-2">
                    <input
                        v-model="testEmailAddress"
                        type="email"
                        placeholder="test@example.com"
                        class="flex-1 rounded-md bg-gray-50 dark:bg-dark-bg border-gray-200 dark:border-dark-border text-gray-900 dark:text-gray-100"
                    />
                    <button
                        type="button"
                        @click="sendTestEmail"
                        :disabled="!testEmailAddress || sendingTest"
                        class="px-4 py-2 bg-gray-200 dark:bg-dark-bg text-gray-700 dark:text-gray-300 rounded-md hover:bg-gray-300 disabled:opacity-50"
                    >
                        {{ sendingTest ? 'Sending...' : 'Send Test Email' }}
                    </button>
                </div>
            </div>

            <!-- Submit Button -->
            <div class="flex justify-end">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="px-4 py-2 bg-primary-400 text-white rounded-md hover:bg-primary-500 disabled:opacity-50"
                >
                    {{ form.processing ? 'Saving...' : 'Save Email Settings' }}
                </button>
            </div>
        </form>
    </div>
</template>
```

---

## Implementation Checklist

### Phase 1: Database & Models
- [ ] Create `settings` table migration
- [ ] Create `email_logs` table migration
- [ ] Create `Setting` model with organization relationship
- [ ] Run migrations

### Phase 2: Backend Services
- [ ] Create `SettingsService` class
- [ ] Create `EmailLogger` service
- [ ] Update `NotificationService` with email sending logic
- [ ] Create Mailable classes (LowStockEmail, OrderStatusEmail, OrderApprovalEmail, TestEmail)
- [ ] Add plugin hooks to NotificationService

### Phase 3: Controllers & Routes
- [ ] Create `SettingsController`
- [ ] Add settings routes (index, updateEmail, testEmail)
- [ ] Add admin permission check middleware

### Phase 4: Email Templates
- [ ] Create `resources/views/emails/layout.blade.php`
- [ ] Create `resources/views/emails/low-stock-alert.blade.php`
- [ ] Create `resources/views/emails/order-status.blade.php`
- [ ] Create `resources/views/emails/order-approval.blade.php`
- [ ] Create `resources/views/emails/test-email.blade.php`
- [ ] Add plugin hooks to templates

### Phase 5: Frontend UI
- [ ] Create `Settings/Index.vue` page with tabs
- [ ] Create `Settings/Partials/EmailSettings.vue` component
- [ ] Create `Settings/Partials/NotificationPreferences.vue` component
- [ ] Add Settings link to navigation menu

### Phase 6: Testing
- [ ] Test SMTP configuration and sending
- [ ] Test PHP Mail configuration
- [ ] Test Mailgun configuration (if available)
- [ ] Test SendGrid configuration (if available)
- [ ] Test user preference toggles
- [ ] Test low stock email notifications
- [ ] Test order status email notifications
- [ ] Test order approval email notifications
- [ ] Test plugin hooks
- [ ] Test email logging

### Phase 7: Documentation
- [ ] Update `todo.txt` to mark email notifications as complete
- [ ] Create user documentation for email settings
- [ ] Document plugin hooks for developers
- [ ] Add examples to PLUGIN_DEVELOPMENT.md

---

## Success Criteria

✅ Organization admins can configure email provider through UI
✅ Supports SMTP, PHP Mail, Mailgun, SendGrid
✅ Sensitive settings (passwords, API keys) are encrypted
✅ Test email functionality works
✅ Low stock alerts send emails to users with manage_stock permission
✅ Order status changes send emails to order creators
✅ Order approvals/rejections send emails to order creators
✅ Users can toggle email notifications per category
✅ Plugin hooks allow extending email functionality
✅ Email logs track sent/failed emails
✅ Multi-tenant architecture preserved (organization-scoped settings)
✅ Responsive UI works on mobile and desktop
✅ Email templates are mobile-friendly and render correctly across email clients

---

## Future Enhancements (Not in Scope)

- Email queue management UI
- Email template customization through UI
- Custom email templates per notification type
- Email delivery statistics and analytics
- Scheduled email reports
- Email template preview before sending
- Multiple from addresses per organization
- CC/BCC options for notifications
- Email bounce handling
- Unsubscribe links and management

---

## Notes

- Email configuration is applied dynamically at runtime using `Config::set()`
- Settings are cached for performance (cleared on update)
- All notification methods in `NotificationService` updated to also send emails
- Email sending is wrapped in try-catch to prevent failures from breaking app
- Plugin hooks follow WordPress-style naming conventions
- Email templates use inline CSS for maximum email client compatibility
- Responsive email design uses tables for better compatibility
- Test email feature helps admins verify configuration before going live
