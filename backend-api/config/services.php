<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // qr-engine-service (Module 4): Baileys QR microservice bridge.
    'qr_engine' => [
        'url' => env('QR_ENGINE_SERVICE_URL', 'http://localhost:4000'),
        // Shared secret — must be IDENTICAL to qr-engine-service's INTERNAL_API_SECRET.
        'internal_secret' => env('INTERNAL_API_SECRET'),
    ],

    // Social Media Marketing & Meta Ads Automation Expansion (Phase 1):
    // the frontend-app SPA's own origin (scheme+host+port, no path), used
    // ONLY to lock down the postMessage targetOrigin in
    // SocialAuthController's OAuth-popup callback page. Not used for CORS
    // (this app has no config/cors.php — CORS is presumably handled at a
    // reverse-proxy layer outside this Structural Context).
    'frontend' => [
        'url' => env('FRONTEND_URL'),
    ],

    // Social Media Marketing & Meta Ads Automation Expansion (Final
    // Phase) — AI Ad Copywriter (CopywriterService). Both optional; when
    // NEITHER key is set (the default — .env.example ships both unset),
    // CopywriterService falls back to its deterministic template engine
    // with no external call at all. OpenAI is tried first when both
    // happen to be set.
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-3-5-haiku-latest'),
    ],

];
