<?php

return [

    'password_reset_url' => env('SIRA_PASSWORD_RESET_URL', 'http://localhost:4200/reset-password'),

    'free' => [
        'max_active_habits' => (int) env('SIRA_FREE_MAX_HABITS', 3),
        'max_active_goals' => (int) env('SIRA_FREE_MAX_GOALS', 3),
        'analytics_max_days' => 7,
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
