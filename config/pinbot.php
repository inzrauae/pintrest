<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Secure page fetching
    |--------------------------------------------------------------------------
    */
    'fetch' => [
        'timeout' => (int) env('PINBOT_FETCH_TIMEOUT', 15),
        'connect_timeout' => (int) env('PINBOT_FETCH_CONNECT_TIMEOUT', 5),
        'max_redirects' => (int) env('PINBOT_FETCH_MAX_REDIRECTS', 5),
        'max_html_bytes' => (int) env('PINBOT_FETCH_MAX_HTML_BYTES', 3 * 1024 * 1024),
        'max_image_bytes' => (int) env('PINBOT_FETCH_MAX_IMAGE_BYTES', 15 * 1024 * 1024),
        'user_agent' => env('PINBOT_FETCH_USER_AGENT', 'PinBot/1.0 (+https://example.com/bot)'),
        // Only these ports may be fetched (blocks probing internal services on odd ports).
        'allowed_ports' => [80, 443, 8080, 8443],
        // Hostnames that are always rejected, regardless of DNS.
        'blocked_hosts' => ['localhost', 'metadata.google.internal', 'metadata'],
    ],

    'extraction' => [
        'max_content_chars' => 12000,
        'max_images' => 25,
        'max_headings' => 30,
    ],

    'ai' => [
        // openai | gemini | fake ("fake" is a deterministic offline generator for local dev and tests)
        'default_provider' => env('PINBOT_AI_PROVIDER', env('OPENAI_API_KEY') ? 'openai' : (env('GEMINI_API_KEY') ? 'gemini' : 'fake')),
        'max_attempts' => 3,
        'content_chars_for_prompt' => 6000,
    ],

    'images' => [
        'default_provider' => env('PINBOT_IMAGE_PROVIDER', env('OPENAI_API_KEY') ? 'openai' : (env('GEMINI_API_KEY') ? 'gemini' : 'none')),
        'disk' => env('PINBOT_IMAGE_DISK', 'local'),
        'output_width' => 1000,
        'output_height' => 1500,
        'jpeg_quality' => 90,
        'min_width' => 300,
        'min_height' => 300,
        'max_upload_kb' => 15 * 1024,
        // Pinterest accepts these formats; everything is normalised to JPEG/PNG on output.
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        'overlay_font' => env('PINBOT_OVERLAY_FONT'),
    ],

    'pins' => [
        'title_max' => 100,
        'description_max' => 500,
        'alt_text_max' => 500,
    ],

    'publishing' => [
        'max_attempts' => 5,
        // Exponential backoff in seconds between publish retries.
        'backoff' => [60, 300, 900, 3600],
        'lock_seconds' => 120,
    ],

    'processing' => [
        'max_attempts' => 3,
        'backoff' => [30, 120, 600],
        'max_bulk_urls' => (int) env('PINBOT_MAX_BULK_URLS', 500),
    ],

    'languages' => [
        'en' => 'English', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German', 'it' => 'Italian',
        'pt' => 'Portuguese', 'nl' => 'Dutch', 'sv' => 'Swedish', 'pl' => 'Polish', 'ja' => 'Japanese',
        'ko' => 'Korean', 'zh' => 'Chinese', 'hi' => 'Hindi', 'si' => 'Sinhala', 'ar' => 'Arabic',
    ],
];
