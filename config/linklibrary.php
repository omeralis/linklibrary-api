<?php

/*
|--------------------------------------------------------------------------
| LinkLibrary product configuration
|--------------------------------------------------------------------------
| All branding, URLs and policy knobs live here so the final brand name and
| deployment topology can change without code changes.
*/

return [

    'brand' => [
        'name' => env('BRAND_NAME', env('APP_NAME', 'LinkLibrary')),
        'support_email' => env('BRAND_SUPPORT_EMAIL', 'support@example.com'),
        'privacy_url' => env('BRAND_PRIVACY_URL'),
        // Shown to users wherever external AI processing is disclosed.
        'ai_disclosure_url' => env('BRAND_AI_DISCLOSURE_URL'),
    ],

    'frontend' => [
        'url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/'),
        // Shared secret for server-side rendering (Next.js server → API). When the header
        // X-Frontend-Key matches, anonymous rate limits are keyed by the end user's IP passed in
        // X-Client-IP instead of the Next.js server's single IP. Leave empty to disable.
        'server_key' => env('FRONTEND_SERVER_KEY'),
        // Path templates; {token}, {publicId}, {username} are substituted.
        'paths' => [
            'link' => env('FRONTEND_PATH_LINK', '/l/{publicId}'),
            'collection' => env('FRONTEND_PATH_COLLECTION', '/c/{publicId}'),
            'profile' => env('FRONTEND_PATH_PROFILE', '/u/{username}'),
            'verify_email' => env('FRONTEND_PATH_VERIFY_EMAIL', '/verify-email'),
            'reset_password' => env('FRONTEND_PATH_RESET_PASSWORD', '/reset-password'),
            'accept_invitation' => env('FRONTEND_PATH_ACCEPT_INVITATION', '/invitations/accept'),
        ],
    ],

    'urls' => [
        'max_length' => (int) env('LINK_URL_MAX_LENGTH', 2048),
        // Fragment policy: fragments are dropped from the normalized URL unless they look like
        // client-side routes ("#!" or "#/"), which commonly identify distinct resources.
        'keep_route_fragments' => (bool) env('LINK_KEEP_ROUTE_FRAGMENTS', true),
    ],

    'fetch' => [
        'user_agent' => env('LINK_FETCH_USER_AGENT', 'LinkLibraryBot/1.0 (+https://example.com/bot)'),
        'robots_user_agent' => env('LINK_FETCH_ROBOTS_AGENT', 'LinkLibraryBot'),
        'connect_timeout' => (float) env('LINK_FETCH_CONNECT_TIMEOUT', 5),
        'timeout' => (float) env('LINK_FETCH_TIMEOUT', 15),
        'max_redirects' => (int) env('LINK_FETCH_MAX_REDIRECTS', 5),
        'max_bytes' => (int) env('LINK_FETCH_MAX_BYTES', 2 * 1024 * 1024),
        'max_pdf_bytes' => (int) env('LINK_FETCH_MAX_PDF_BYTES', 5 * 1024 * 1024),
        'allowed_ports' => array_map('intval', explode(',', (string) env('LINK_FETCH_ALLOWED_PORTS', '80,443,8080,8443'))),
        'allow_https_to_http_redirect' => (bool) env('LINK_FETCH_ALLOW_DOWNGRADE', false),
        // Optional explicit egress proxy. It MUST enforce its own SSRF policy (IP pinning is bypassed).
        'proxy' => env('LINK_FETCH_PROXY'),
        'respect_robots' => (bool) env('LINK_FETCH_RESPECT_ROBOTS', true),
        'robots_cache_ttl' => (int) env('LINK_FETCH_ROBOTS_CACHE_TTL', 86400),
        // Additional hostnames (exact or ".suffix") that must never be fetched.
        'blocked_hosts' => array_filter(explode(',', (string) env('LINK_FETCH_BLOCKED_HOSTS', ''))),
    ],

    'security' => [
        // Comma separated: safe_browsing, web_risk, heuristic. "heuristic" can only flag, never clear.
        'providers' => array_filter(explode(',', (string) env('SECURITY_PROVIDERS', 'heuristic'))),
        'clear_ttl_days' => (int) env('SECURITY_CLEAR_TTL_DAYS', 7),
        'rescan_before_expiry_hours' => (int) env('SECURITY_RESCAN_BEFORE_EXPIRY_HOURS', 24),
        'rescan_batch_size' => (int) env('SECURITY_RESCAN_BATCH_SIZE', 200),
        // When no reputation provider can vouch for a URL its status is UNKNOWN. Fetching metadata for
        // UNKNOWN URLs is allowed by default (the SSRF-safe client protects the server); publication is not.
        'fetch_when_unknown' => (bool) env('SECURITY_FETCH_WHEN_UNKNOWN', true),
        'blocklist_hosts' => array_filter(explode(',', (string) env('SECURITY_BLOCKLIST_HOSTS', ''))),
        'safe_browsing' => [
            'api_key' => env('GOOGLE_SAFE_BROWSING_API_KEY'),
            'client_id' => env('GOOGLE_SAFE_BROWSING_CLIENT_ID', 'linklibrary'),
            'client_version' => env('GOOGLE_SAFE_BROWSING_CLIENT_VERSION', '1.0.0'),
            'endpoint' => env('GOOGLE_SAFE_BROWSING_ENDPOINT', 'https://safebrowsing.googleapis.com/v4/threatMatches:find'),
            'timeout' => (float) env('GOOGLE_SAFE_BROWSING_TIMEOUT', 5),
        ],
        'web_risk' => [
            'api_key' => env('GOOGLE_WEB_RISK_API_KEY'),
            'endpoint' => env('GOOGLE_WEB_RISK_ENDPOINT', 'https://webrisk.googleapis.com/v1/uris:search'),
            'timeout' => (float) env('GOOGLE_WEB_RISK_TIMEOUT', 5),
        ],
    ],

    'extraction' => [
        'stale_after_days' => (int) env('EXTRACTION_STALE_AFTER_DAYS', 30),
        'max_attempts' => (int) env('EXTRACTION_MAX_ATTEMPTS', 4),
        'backoff' => [60, 300, 900],
        'text_excerpt_chars' => (int) env('EXTRACTION_TEXT_EXCERPT_CHARS', 6000),
        'github_token' => env('GITHUB_API_TOKEN'),
        'youtube_api_key' => env('YOUTUBE_API_KEY'),
    ],

    'ai' => [
        // anthropic | openai | null
        'provider' => env('AI_PROVIDER', 'null'),
        'timeout' => (float) env('AI_TIMEOUT', 30),
        'max_input_chars' => (int) env('AI_MAX_INPUT_CHARS', 8000),
        'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 1200),
        'daily_request_limit' => (int) env('AI_DAILY_REQUEST_LIMIT', 1000),
        'max_attempts' => (int) env('AI_MAX_ATTEMPTS', 3),
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-haiku-4-5'),
            'endpoint' => env('ANTHROPIC_ENDPOINT', 'https://api.anthropic.com/v1/messages'),
            'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
        ],
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
            // Any OpenAI-compatible chat completions endpoint (OpenAI, Azure-compatible gateways, Ollama...).
            'endpoint' => env('OPENAI_ENDPOINT', 'https://api.openai.com/v1/chat/completions'),
        ],
    ],

    'sharing' => [
        'invitation_ttl_hours' => (int) env('INVITATION_TTL_HOURS', 168),
        'max_pending_invitations_per_link' => (int) env('INVITATION_MAX_PENDING_PER_LINK', 50),
    ],

    'discovery' => [
        'cache_ttl' => (int) env('DISCOVERY_CACHE_TTL', 60),
        'popularity_window_days' => (int) env('POPULARITY_WINDOW_DAYS', 30),
    ],

    'pagination' => [
        'default' => 20,
        'max' => 50,
    ],

    'search' => [
        'min_query_length' => 2,
        'max_query_length' => 200,
        'max_terms' => 12,
    ],

    'uploads' => [
        'disk' => env('UPLOADS_DISK', 'public'),
        'max_image_kb' => (int) env('UPLOADS_MAX_IMAGE_KB', 2048),
    ],

    'retention' => [
        'extraction_jobs_days' => (int) env('RETENTION_EXTRACTION_JOBS_DAYS', 30),
        'security_scans_days' => (int) env('RETENTION_SECURITY_SCANS_DAYS', 180),
        'link_views_days' => (int) env('RETENTION_LINK_VIEWS_DAYS', 90),
        'expired_invitations_days' => (int) env('RETENTION_EXPIRED_INVITATIONS_DAYS', 30),
    ],

    'usernames' => [
        'reserved' => [
            'admin', 'administrator', 'api', 'app', 'auth', 'login', 'logout', 'register', 'me', 'settings',
            'support', 'help', 'root', 'system', 'public', 'search', 'links', 'collections', 'invitations',
            'notifications', 'about', 'privacy', 'terms', 'null', 'undefined', 'www', 'mail',
        ],
    ],
];
