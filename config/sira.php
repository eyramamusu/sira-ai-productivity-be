<?php

return [

    // Where reset emails link to. Defaults to the reset page this API hosts, which works in any
    // browser. Set it to the app's /reset-password screen only for a web build of the app.
    'password_reset_url' => env('SIRA_PASSWORD_RESET_URL') ?: rtrim((string) env('APP_URL', 'http://localhost'), '/').'/reset-password',

    // Where profile photos are saved: `disk` (the public disk, served from /storage) or `database`
    // (the user_avatars table, for hosts whose disk is wiped on restart, such as Render's free tier).
    'avatars' => [
        'storage' => env('SIRA_AVATAR_STORAGE', 'disk'),
    ],

    // Shared secret for GET/POST /api/v1/cron/run (header X-Cron-Secret), which runs the scheduler
    // on hosts without cron. Leave empty to disable the endpoint.
    'cron_secret' => env('SIRA_CRON_SECRET', ''),

    'free' => [
        'max_active_habits' => (int) env('SIRA_FREE_MAX_HABITS', 3),
        'max_active_goals' => (int) env('SIRA_FREE_MAX_GOALS', 3),
        'analytics_max_days' => 7,
        // Tasks the user adds per day (in their time zone). Repeat occurrences the server creates don't count.
        'max_tasks_per_day' => (int) env('SIRA_FREE_MAX_TASKS_PER_DAY', 5),
        'max_subtasks_per_task' => (int) env('SIRA_FREE_MAX_SUBTASKS', 2),
        // Free focus sessions are fixed at this length; Premium picks 60 minutes or a custom length.
        'focus_minutes' => 25,
    ],

    'premium' => [
        'analytics_max_days' => 366,
    ],

    // Plan => store product id. The same ids exist in Play, App Store and RevenueCat.
    'products' => [
        'monthly' => env('SIRA_PRODUCT_MONTHLY', 'sira_premium_monthly'),
        'annual' => env('SIRA_PRODUCT_ANNUAL', 'sira_premium_annual'),
    ],

    // Keep in sync with src/app/core/palettes.ts. Only the first one is free.
    'color_themes' => [
        'sira', 'ocean', 'forest', 'teal', 'lavender', 'rose', 'crimson',
        'sunset', 'gold', 'slate', 'midnight', 'olive', 'plum',
    ],
    'free_color_theme' => 'sira',

    'default_categories' => [
        ['name' => 'Work', 'color' => '#8B5A3C', 'icon' => 'work'],
        ['name' => 'Personal', 'color' => '#D4A373', 'icon' => 'person'],
        ['name' => 'Health', 'color' => '#6A994E', 'icon' => 'favorite'],
        ['name' => 'Learning', 'color' => '#457B9D', 'icon' => 'school'],
        ['name' => 'Errands', 'color' => '#BC6C25', 'icon' => 'shopping_cart'],
    ],

    'billing' => [
        'driver' => env('SIRA_BILLING_DRIVER', 'revenuecat'),

        'revenuecat' => [
            'secret_api_key' => env('REVENUECAT_SECRET_API_KEY'),
            'entitlement' => env('REVENUECAT_ENTITLEMENT', 'premium'),
            'webhook_auth' => env('REVENUECAT_WEBHOOK_AUTH') ?: null,
        ],

        'google' => [
            'service_account_json' => env('GOOGLE_PLAY_SERVICE_ACCOUNT_JSON') ?: null,
            'package_name' => env('GOOGLE_PLAY_PACKAGE_NAME', 'com.sira.productivity'),
            'rtdn_token' => env('GOOGLE_PLAY_RTDN_TOKEN') ?: null,
        ],

        'apple' => [
            'bundle_id' => env('APPLE_BUNDLE_ID', 'com.sira.productivity'),
            'issuer_id' => env('APPLE_IAP_ISSUER_ID') ?: null,
            'key_id' => env('APPLE_IAP_KEY_ID') ?: null,
            'private_key_path' => env('APPLE_IAP_PRIVATE_KEY_PATH') ?: null,
            'environment' => env('APPLE_IAP_ENVIRONMENT', 'production'),
        ],
    ],

    // Server-sent notifications (security alerts, subscription changes, weekly review).
    // Scheduled reminders are local notifications on the phone and need none of this.
    'push' => [
        // native: FCM for Android, APNs for iOS (each only once its credentials are set) | log
        'driver' => env('SIRA_PUSH_DRIVER', 'native'),

        'fcm' => [
            'service_account_json' => env('FCM_SERVICE_ACCOUNT_JSON') ?: null,
        ],

        'apns' => [
            'team_id' => env('APNS_TEAM_ID') ?: null,
            'key_id' => env('APNS_KEY_ID') ?: null,
            'private_key_path' => env('APNS_PRIVATE_KEY_PATH') ?: null,
            'bundle_id' => env('APPLE_BUNDLE_ID', 'com.sira.productivity'),
            'environment' => env('APNS_ENVIRONMENT', 'production'),
        ],

        'weekly_review_hour' => 9,
        'keep_read_days' => 90,
    ],

    'ai' => [
        'driver' => env('SIRA_AI_DRIVER', 'anthropic'),
        'daily_request_limit' => (int) env('SIRA_AI_DAILY_LIMIT', 50),

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY') ?: null,
            'model' => env('SIRA_AI_MODEL', 'claude-opus-5-5'),
            'effort' => env('SIRA_AI_EFFORT', 'medium'),
        ],
    ],

];
