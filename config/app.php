<?php
/**
 * Committed application configuration. No credentials live here.
 *
 * Anything secret (database password, status_key, setup_key, cron_key,
 * diag_key, mail credentials) belongs in config/local.php, which is
 * gitignored, lives outside public_html and survives every deploy
 * (hosting Section 6.4).
 *
 * Every value below can be overridden by an environment variable using the
 * CARL_ prefix and an underscore path, e.g. CARL_DB_HOST, CARL_BASE_PATH.
 */

return [
    // --- Identity -------------------------------------------------------
    'app_name'  => 'Carl',
    'app_title' => 'Carl The Garden Helper',

    // Hosting Section 5.2: the app is served from a subpath and NOTHING may
    // hard-code a site-root path. This is the single place the value appears.
    'base_path' => '/carl/',

    // --- Database (hosting Section 2.1 / 7) -----------------------------
    // NEVER localhost: MySQL runs on separate hardware.
    'db' => [
        'host'    => '152.160.193.196',
        'port'    => 3306,
        'name'    => 'reshiftmanager_carl',
        'user'    => '',   // config/local.php
        'pass'    => '',   // config/local.php
        'charset' => 'utf8mb4',
        // Guard rail for hosting Section 2.1: pointing at localhost reaches a
        // real MySQL that has never heard of this account and answers with a
        // misleading plugin error. Only CI and local dev set this true.
        'allow_local' => false,
    ],

    // --- Sessions (hosting Section 8.1) ---------------------------------
    'session' => [
        'name'      => 'CARLSESS',
        // 'path' defaults to base_path; 'secure' defaults to whether the
        // request arrived over HTTPS, so local http dev still works.
        'save_path' => null,   // defaults to <app_root>/var/sessions
    ],

    // --- Auth -----------------------------------------------------------
    'auth' => [
        'bcrypt_cost'        => 11,   // hosting Section 8.4
        'token_lifetime_days' => 30,  // rolling; rotated on every use
        'throttle' => [
            'max_attempts' => 10,     // hosting Section 8.4: deliberately loose
            'window_sec'   => 900,
            'lockout_sec'  => 60,
        ],
    ],

    // --- Keys (all null here; set in config/local.php) -------------------
    // A null key means the route does not exist and returns 404, which is
    // the state to leave setup_key and diag_key in (hosting Section 6.3).
    'status_key' => null,
    'setup_key'  => null,
    'cron_key'   => null,
    'diag_key'   => null,

    // --- Photos (handoff Section 10) ------------------------------------
    'photos' => [
        'max_bytes'      => 2 * 1024 * 1024,   // upload_max_filesize is 2M
        'max_megapixels' => 40,                // decompression-bomb guard
        'long_edge'      => 1920,
        'thumb_edge'     => 320,
        'jpeg_quality'   => 85,
    ],

    // --- Research import (handoff Section 9.3) --------------------------
    'research' => [
        'template_version'    => 1,
        'max_zip_bytes'       => 2 * 1024 * 1024,
        'max_entry_bytes'     => 5 * 1024 * 1024,  // uncompressed, per entry
        'upsert_chunk_rows'   => 200,              // hosting Section 9
    ],

    // --- Weather (weather.md) -------------------------------------------
    'weather' => [
        'archive_url'   => 'https://archive-api.open-meteo.com/v1/archive',
        'forecast_url'  => 'https://api.open-meteo.com/v1/forecast',
        'ncei_url'      => 'https://www.ncei.noaa.gov/access/services/data/v1',
        'alerts_url'    => 'https://api.weather.gov/alerts/active',
        'zip_api_url'   => 'https://api.zippopotam.us/us/',
        'user_agent'    => 'CarlTheGardenHelper/1.0 (https://www.reshiftmanager.com/carl/; carl@reshiftmanager.com)',
        'http_timeout'  => 20,
        'retry_delay'   => 30,        // weather.md Section 8.1: one retry, then stop
        'revision_days' => 14,        // rolling re-fetch window
        'settle_days'   => 10,        // older than this stops being provisional
        'upsert_chunk_rows' => 200,
        'run_retention_days' => 90,
        'forecast_days' => 7,
        'past_days'     => 7,
    ],

    // --- Display --------------------------------------------------------
    // Store SI, convert at display (weather.md Section 6.3).
    'units' => 'us',   // 'us' => F / in / mph ; 'si' => C / mm / km/h
];
