# Email Notifications

Inventoros includes a comprehensive email notification system that keeps users informed about critical inventory events, order updates, and approval workflows.

## Table of Contents

- [Overview](#overview)
- [Configuration](#configuration)
  - [Email Provider Setup](#email-provider-setup)
  - [Provider Options](#provider-options)
- [User Preferences](#user-preferences)
- [Notification Types](#notification-types)
- [Testing Email Configuration](#testing-email-configuration)
- [Troubleshooting](#troubleshooting)
- [For Developers](#for-developers)

## Overview

The email notification system provides:

- **Multi-provider support**: SMTP, PHP Mail, Mailgun, and SendGrid
- **Organization-scoped configuration**: Each organization manages its own email settings
- **Granular user preferences**: Users can control which notifications they receive
- **Professional email templates**: Pre-designed templates for all notification types
- **Email delivery tracking**: Monitor sent emails and track errors
- **Test email functionality**: Verify configuration before going live
- **Plugin extensibility**: Developers can add custom notification types

## Configuration

### Email Provider Setup

Only administrators can configure email settings for their organization.

1. Navigate to **Settings > Email Configuration**
2. Select your email provider from the dropdown
3. Fill in the required configuration fields for your provider
4. Click **Save Configuration**
5. Test your configuration using the "Send Test Email" feature

### Provider Options

#### SMTP

Use any SMTP server (Gmail, Outlook, your own mail server, etc.).

**Required fields:**
- **Host**: SMTP server address (e.g., `smtp.gmail.com`)
- **Port**: SMTP port (typically 587 for TLS, 465 for SSL)
- **Username**: Your email account username
- **Password**: Your email account password
- **Encryption**: `TLS` or `SSL`
- **From Address**: Email address that emails will be sent from
- **From Name**: Display name for the sender

**Example (Gmail):**
```
Host: smtp.gmail.com
Port: 587
Username: your-email@gmail.com
Password: your-app-password
Encryption: TLS
From Address: your-email@gmail.com
From Name: Inventoros Notifications
```

**Important for Gmail users:** You must use an [App Password](https://support.google.com/accounts/answer/185833) instead of your regular password.

#### PHP Mail

Uses the server's built-in mail function. No additional configuration required.

**Required fields:**
- **From Address**: Email address that emails will be sent from
- **From Name**: Display name for the sender

**Note:** Requires proper mail server configuration on your hosting server. Not recommended for high-volume sending.

#### Mailgun

Commercial email service with excellent deliverability.

**Required fields:**
- **Domain**: Your verified Mailgun domain
- **API Key**: Your Mailgun API key
- **Endpoint**: API endpoint (usually `api.mailgun.net` for US or `api.eu.mailgun.net` for EU)
- **From Address**: Email address that emails will be sent from
- **From Name**: Display name for the sender

**Setup:**
1. Create a [Mailgun account](https://www.mailgun.com/)
2. Verify your domain
3. Copy your API key from the dashboard
4. Enter the details in Inventoros

#### SendGrid

Another popular commercial email service.

**Required fields:**
- **API Key**: Your SendGrid API key
- **From Address**: Verified sender email address
- **From Name**: Display name for the sender

**Setup:**
1. Create a [SendGrid account](https://sendgrid.com/)
2. Verify your sender identity
3. Create an API key with "Mail Send" permissions
4. Enter the details in Inventoros

## User Preferences

Users can customize which email notifications they receive.

### Accessing Preferences

1. Click your profile icon in the top right
2. Select **Notification Preferences**
3. Configure your email notification settings

### Preference Options

**Master Email Toggle:**
- Turn ON to receive email notifications
- Turn OFF to disable all email notifications (you'll still receive in-app notifications)

**Category-Specific Preferences:**

When email notifications are enabled, you can control individual categories:

- **Low Stock Alerts**: Receive alerts when products fall below their minimum stock level
- **Order Notifications**: Get notified about order status changes
- **Approval Requests**: Receive notifications when orders require your approval
- **System Notifications**: Important system updates and announcements

Each category can be toggled independently, allowing you to receive only the notifications you care about.

## Notification Types

### Low Stock Alerts

Automatically sent when a product's stock falls below the minimum stock level defined for that product.

**Includes:**
- Product name and SKU
- Current stock level
- Minimum stock threshold
- Direct link to the product

**Sent to:** Users with the `inventory.manage` permission who have low stock alerts enabled

### Order Status Updates

Sent when an order's status changes.

**Includes:**
- Order number
- Previous and new status
- Order total
- Customer information
- Direct link to the order

**Status changes that trigger notifications:**
- Pending → Completed
- Pending → Cancelled
- Approved → Completed

**Sent to:**
- Order creator
- Users with `orders.manage` permission who have order notifications enabled

### Order Approval Requests

Sent when an order requires approval (typically for high-value orders or those requiring special authorization).

**Includes:**
- Order number
- Order total
- Customer information
- Items in the order
- Requester information
- Direct link to approve/reject

**Sent to:** Users with the `orders.approve` permission who have approval notifications enabled

## Testing Email Configuration

After configuring your email provider, always test the configuration:

1. Go to **Settings > Email Configuration**
2. Enter a test email address in the "Test Email" field
3. Click **Send Test Email**
4. Check the recipient's inbox for the test message

**If the test fails:**
- Check the error message displayed
- Verify all configuration fields are correct
- Check the Email Logs for detailed error information
- See [Troubleshooting](#troubleshooting) below

## Troubleshooting

### Emails Not Being Sent

**Check Email Configuration:**
1. Go to **Settings > Email Configuration**
2. Verify all fields are filled correctly
3. Send a test email to confirm configuration

**Check User Preferences:**
1. Ensure the user has email notifications enabled
2. Verify the specific notification category is enabled
3. Check that the user's email address is valid

**Check Email Logs:**
1. Go to **Settings > Email Logs** (admin only)
2. Look for failed delivery attempts
3. Review error messages for specific issues

### Common Issues

#### "Connection refused" or "Could not connect to host"

**Cause:** Cannot reach the SMTP server

**Solutions:**
- Verify the host and port are correct
- Check your firewall settings
- Confirm your hosting provider allows outbound SMTP connections
- Try a different port (587 vs 465)

#### "Authentication failed"

**Cause:** Invalid username or password

**Solutions:**
- Double-check your credentials
- For Gmail: Use an App Password instead of your regular password
- For SendGrid/Mailgun: Verify your API key is correct and has the right permissions

#### "Sender address rejected"

**Cause:** From address is not authorized

**Solutions:**
- Verify the from address matches your account
- For commercial services: Ensure the sender address is verified in your provider's dashboard
- Check if your SMTP server requires matching from/auth addresses

#### "Rate limit exceeded"

**Cause:** Sending too many emails too quickly

**Solutions:**
- For free tiers: Upgrade to a paid plan with higher limits
- Implement email batching (contact support)
- Space out notification-triggering actions

#### Emails go to spam

**Cause:** Poor sender reputation or missing SPF/DKIM records

**Solutions:**
- Use a commercial email service (Mailgun, SendGrid) for better deliverability
- Configure SPF and DKIM records for your domain
- Avoid using free email services (Gmail, Yahoo) as the from address
- Use a dedicated sending domain

### Email Logs

Administrators can view email delivery logs:

1. Navigate to **Settings > Email Logs**
2. View all sent emails with status (sent/failed)
3. Click on a log entry to see details and error messages
4. Use filters to find specific emails

**Log information includes:**
- Recipient email address
- Subject line
- Notification type
- Status (sent/failed)
- Error message (if failed)
- Timestamp

## For Developers

### Plugin Hooks

Developers can extend the email notification system using plugin hooks.

**Available hooks:**

```php
// Modify email content before sending
do_action('email.before_send', $mailable, $user);

// After email is sent
do_action('email.after_send', $mailable, $user, $success);

// Add custom notification types
add_filter('email.notification_types', function($types) {
    $types['custom_alert'] = 'Custom Alert Notifications';
    return $types;
});

// Customize email templates
add_filter('email.template_path', function($path, $type) {
    if ($type === 'custom_alert') {
        return plugin_path('templates/email/custom-alert.blade.php');
    }
    return $path;
}, 10, 2);
```

### Creating Custom Mailables

Create a new Mailable class in your plugin:

```php
<?php

namespace YourPlugin\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CustomNotification extends Mailable
{
    use SerializesModels;

    public function __construct(
        public $data,
        public $organization
    ) {}

    public function build()
    {
        return $this->subject('Custom Notification')
                    ->view('your-plugin::emails.custom-notification');
    }
}
```

### Sending Emails from Plugins

Use the NotificationService to send emails:

```php
use App\Services\NotificationService;

$notificationService = app(NotificationService::class);

$notificationService->sendEmail(
    user: $user,
    mailable: new CustomNotification($data, $organization),
    category: 'custom_alert'
);
```

### Email Template Structure

Email templates are Blade files located in `resources/views/emails/`:

```blade
@component('mail::message')
# {{ $title }}

{{ $content }}

@component('mail::button', ['url' => $actionUrl])
{{ $actionText }}
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
```

### Testing in Development

When developing locally, consider using [Mailtrap](https://mailtrap.io/) or [MailHog](https://github.com/mailhog/MailHog) to capture outgoing emails without actually sending them.

---

## Support

If you encounter issues not covered in this guide:

1. Check the Email Logs for detailed error messages
2. Review your email provider's documentation
3. Contact your system administrator
4. Open an issue on the Inventoros GitHub repository

---

**Last Updated:** February 2026
**Version:** 1.0
