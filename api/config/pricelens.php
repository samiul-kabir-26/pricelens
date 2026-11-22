<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Scraper defaults
    |--------------------------------------------------------------------------
    */
    'scraper' => [
        'user_agent' => env('SCRAPER_USER_AGENT', 'PriceLensBot/1.0 (+https://pricelens.example/bot)'),
        'default_rps_per_domain' => (float) env('SCRAPER_DEFAULT_RPS_PER_DOMAIN', 0.5),
        'default_domain_concurrency' => (int) env('SCRAPER_DEFAULT_DOMAIN_CONCURRENCY', 2),
        'max_body_bytes' => (int) env('SCRAPER_MAX_BODY_BYTES', 2 * 1024 * 1024),
        'timeout_seconds' => (int) env('SCRAPER_TIMEOUT_SECONDS', 15),
        'max_redirects' => 5,
        // Non-production only: hosts allowed to resolve to private IPs (fixture farm, canary). Comma list, *.suffix ok.
        'ssrf_allow_hosts' => array_filter(explode(',', (string) env('PRICELENS_SSRF_ALLOW_HOSTS', ''))),
        'retry_backoff' => [10, 30, 90],
        'circuit_failures' => (int) env('SCRAPER_CIRCUIT_FAILURES', 10),
        'circuit_window_seconds' => 300,
        'circuit_open_seconds' => 900,
        'robots_ttl_seconds' => 86400,
        'html_cache_ttl_seconds' => 1800,
        'max_candidates_per_source' => 10,
        'max_query_variants' => 3,
        'tracking_params' => [
            'utm_*', 'gclid', 'fbclid', 'msclkid', 'dclid', 'ref', 'ref_', 'tag', 'mc_cid', 'mc_eid',
            'igshid', 'yclid', '_hsenc', '_hsmkt', 'spm', 'srsltid',
        ],
        // Used for domains with no adapter: tried in order, `{query}` is URL-encoded.
        'generic_search_templates' => [
            'https://{domain}/search?q={query}',
            'https://{domain}/?s={query}&post_type=product',
        ],
        'generic_product_url_patterns' => [
            '#/products?/[\w%-]+#i',
            '#/p/[\w%-]+#i',
            '#/dp/[A-Z0-9]{10}#',
            '#/item/[\w%-]+#i',
            '#/shop/[\w%-]+/[\w%-]+#i',
            '#[-/]\d{5,}\.html?$#i',
        ],
    ],

    /*
    | Sitemap discovery: product URLs indexed from site sources' `sitemap_urls` (robots-compliant discovery for
    | shops whose search pages are disallowed). Refreshed when older than refresh_hours.
    */
    'sitemaps' => [
        'refresh_hours' => (int) env('PRICELENS_SITEMAP_REFRESH_HOURS', 24),
        'max_files_per_source' => (int) env('PRICELENS_SITEMAP_MAX_FILES', 500),
        'seconds_between_files' => (int) env('PRICELENS_SITEMAP_FILE_SPACING', 2),
        'max_download_bytes' => 50 * 1024 * 1024,
        'max_xml_bytes' => 200 * 1024 * 1024,
    ],

    'images' => [
        'max_bytes' => 10 * 1024 * 1024,
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    ],

    'renderer' => [
        'url' => env('RENDERER_URL', 'http://renderer:3100'),
        'token' => env('RENDERER_TOKEN'),
        'timeout_ms' => (int) env('RENDERER_TIMEOUT_MS', 20000),
    ],

    'providers' => [
        'default_web' => env('PRICELENS_DEFAULT_WEB_PROVIDER', 'brave'),
        'default_shopping' => env('PRICELENS_DEFAULT_SHOPPING_PROVIDER', 'serpapi'),
        'default_reverse_image' => env('PRICELENS_DEFAULT_REVERSE_IMAGE_PROVIDER', 'serpapi'),
        'cache_ttl_seconds' => 6 * 3600,
        'serpapi' => ['key' => env('SERPAPI_KEY'), 'base_url' => 'https://serpapi.com/search.json'],
        'brave' => ['key' => env('BRAVE_SEARCH_KEY'), 'base_url' => 'https://api.search.brave.com/res/v1/web/search'],
        'google_vision' => [
            'credentials_path' => env('GOOGLE_VISION_CREDENTIALS_PATH'),
            'api_key' => env('GOOGLE_VISION_API_KEY'),
            'base_url' => 'https://vision.googleapis.com/v1/images:annotate',
        ],
    ],

    'llm' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('PRICELENS_LLM_MODEL', 'claude-opus-5-5'),
        'effort' => env('PRICELENS_LLM_EFFORT', 'low'),
        'max_input_chars' => 12000,
        'max_field_confidence' => 0.9,
    ],

    'matching' => [
        'weights' => [
            'title' => 0.40,
            'model' => 0.20,
            'brand' => 0.15,
            'image' => 0.15,
            'variant' => 0.10,
        ],
        'hard_negative_multiplier' => 0.3,
        'bands' => ['exact' => 0.85, 'likely' => 0.60, 'possible' => 0.40],
        'accessory_keywords' => [
            'case', 'cover', 'replacement', 'strap', 'charger for', 'screen protector', 'skin',
            'sleeve', 'cable for', 'adapter for', 'ear pads', 'earpads', 'cushion', 'mount for',
            'compatible with', 'for use with', 'holder', 'decal', 'tempered glass',
        ],
        'marketing_noise' => [
            'new', 'brand new', 'free shipping', 'fast shipping', 'best price', 'hot sale', 'sale',
            'official', 'genuine', 'original', 'authentic', 'latest', 'limited offer', 'deal',
            '\d{4} model', 'in stock', 'ships today',
        ],
        'colors' => [
            'black', 'white', 'silver', 'gray', 'grey', 'blue', 'red', 'green', 'gold', 'rose gold',
            'pink', 'purple', 'yellow', 'orange', 'brown', 'beige', 'midnight', 'starlight',
            'graphite', 'navy', 'teal', 'platinum', 'space gray', 'titanium',
        ],
        'brands' => [
            'Apple', 'Samsung', 'Sony', 'LG', 'Bose', 'Google', 'Microsoft', 'Dell', 'HP', 'Lenovo',
            'Asus', 'Acer', 'Nintendo', 'Canon', 'Nikon', 'Fujifilm', 'Panasonic', 'Philips', 'Xiaomi',
            'OnePlus', 'Huawei', 'JBL', 'Sennheiser', 'Logitech', 'Razer', 'Garmin', 'Fitbit', 'Dyson',
            'Bosch', 'Makita', 'DeWalt', 'Nike', 'Adidas', 'Anker', 'Beats', 'GoPro', 'DJI', 'Kindle',
            'Amazon', 'Seagate', 'Western Digital', 'SanDisk', 'Kingston', 'Corsair', 'Intel', 'AMD', 'Nvidia',
        ],
        'brand_aliases' => [
            'hewlett packard' => 'HP',
            'hewlett-packard' => 'HP',
            'wd' => 'Western Digital',
            'beats by dre' => 'Beats',
            'lg electronics' => 'LG',
        ],
    ],

    'auth' => [
        // Spec open item #2 default: tenants are provisioned by an admin; set true to allow self-serve signup.
        'self_signup' => (bool) env('PRICELENS_SELF_SIGNUP', false),
        'ui_url' => env('PRICELENS_UI_URL', env('APP_URL', 'http://localhost:8080')),
    ],

    'monitoring' => [
        'partition_months_ahead' => 3,
        // null = keep price history forever; otherwise drop monthly partitions older than N months.
        'snapshot_retention_months' => env('PRICELENS_SNAPSHOT_RETENTION_MONTHS') ? (int) env('PRICELENS_SNAPSHOT_RETENTION_MONTHS') : null,
    ],

    'webhooks' => [
        'timeout_seconds' => 10,
        'backoff_seconds' => [60, 300, 900, 3600, 10800, 21600, 43200, 86400],
        'max_attempts' => 8,
        'disable_after_failures' => 50,
        'signature_tolerance_seconds' => 300,
    ],

    'realtime' => [
        'progress_broadcasts_per_second' => 2,
    ],

    'fx' => [
        'ecb_url' => 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml',
        'max_staleness_days' => 7,
    ],

    'defaults' => [
        'plan' => 'starter',
        'max_results' => 50,
        'min_confidence' => 0.6,
        'retention_days' => 90,
    ],

    'otel' => [
        'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT'),
        'service_name' => env('OTEL_SERVICE_NAME', 'pricelens-api'),
    ],

    'metrics_token' => env('PRICELENS_METRICS_TOKEN'),

    'errors_base_url' => env('PRICELENS_ERRORS_BASE_URL', 'https://docs.pricelens.dev/errors/'),
];
