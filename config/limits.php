<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Timeout Settings
    |--------------------------------------------------------------------------
    */
    'timeouts' => [
        'update_operation' => 600,      // 10 minutes for update/restore
        'github_api' => 30,             // GitHub API requests
        'file_download' => 300,         // Large file downloads (5 min)
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination Settings
    |--------------------------------------------------------------------------
    */
    'pagination' => [
        'default' => 15,                // Standard list pages
        'medium' => 20,                 // Users, roles, notifications
        'large' => 50,                  // Activity logs, reports
    ],

    /*
    |--------------------------------------------------------------------------
    | File Upload Limits (in KB)
    |--------------------------------------------------------------------------
    */
    'uploads' => [
        'import_max_kb' => 10240,       // 10 MB for CSV/XLSX imports
        'plugin_max_kb' => 51200,       // 50 MB for plugin ZIPs
        'image_max_kb' => 5120,         // 5 MB per image
        'max_images' => 5,              // Max images per product
    ],

    /*
    |--------------------------------------------------------------------------
    | Directory Permissions
    |--------------------------------------------------------------------------
    */
    'permissions' => [
        'directory' => 0755,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard & Display Limits
    |--------------------------------------------------------------------------
    */
    'dashboard' => [
        'recent_items' => 5,            // Recent products, orders
        'activity_items' => 10,         // Recent activity logs
        'top_products' => 10,           // Top products by revenue
    ],

    /*
    |--------------------------------------------------------------------------
    | Order Settings
    |--------------------------------------------------------------------------
    */
    'orders' => [
        'number_start' => '0001',       // Starting order number
        'number_padding' => 4,          // Zero-pad length
    ],

    /*
    |--------------------------------------------------------------------------
    | Document Emails
    |--------------------------------------------------------------------------
    | Emailing purchase orders and invoices (web, REST and MCP) is rate
    | limited so it cannot be used to relay mail to arbitrary addresses.
    */
    'document_emails' => [
        'per_user_per_minute' => (int) env('DOCUMENT_EMAILS_PER_USER_PER_MINUTE', 10),
        'per_organization_per_day' => (int) env('DOCUMENT_EMAILS_PER_ORGANIZATION_PER_DAY', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation Limits
    |--------------------------------------------------------------------------
    */
    'validation' => [
        'name_max' => 255,
        'currency_code_max' => 3,
        'short_code_max' => 50,
        'notes_max' => 500,
        'language_code_max' => 10,
    ],
];
