<?php

use App\Models\User;

return [

    'profile_fields' => [
        'about' => [
            'label' => 'About',
            'field' => 'textarea',
            'validation' => 'required',
        ],
    ],

    'api' => [
        'auth_token_expires' => 60,
        'key_token_expires' => 1,
    ],

    'auth' => [
        'min_password_length' => 12,
    ],

    'primary_color' => '#7b9041',

    'user_model' => User::class,
    'demo' => env('WAVE_DEMO', false),
    'default_user_role' => 'Free',

    'billing_provider' => env('BILLING_PROVIDER', 'stripe'),

    // Tailwind color name used for the checkout buttons and billing-cycle toggle.
    // Relocated from the removed config/devdojo/billing/style.php.
    'billing_color' => 'primary',

    'paddle' => [
        'vendor' => env('PADDLE_VENDOR_ID', ''),
        'api_key' => env('PADDLE_API_KEY', ''),
        'env' => env('PADDLE_ENV', 'sandbox'),
        'public_key' => env('PADDLE_PUBLIC_KEY', ''),
        'webhook_secret' => env('PADDLE_WEBHOOK_SECRET', ''),
    ],

    'stripe' => [
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

];
