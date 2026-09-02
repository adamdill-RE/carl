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

    // --- QR plant tags (docs/QR-TAGS-SPEC.md) ---------------------------
    'tags' => [
        // The absolute origin a printed tag points at. It has to be absolute:
        // a QR code is read by a camera app that has no page to be relative
        // to. The path is still built from base_path through $app->url(), so
        // hosting Section 5.2 is untouched -- this is the scheme and host and
        // nothing else.
        //
        // Three other places still spell this out inline (AdminController's
        // invitation link and two in Reminders\Digest). They predate this key
        // and are left alone deliberately -- changing what a live mail path
        // builds is not a change to make alongside a new feature -- but they
        // should move here.
        'origin' => 'https://www.reshiftmanager.com',

        // Upper-case the whole tag URL, which buys alphanumeric encoding: a
        // version 3 symbol instead of version 4, and 0.649 mm modules instead
        // of 0.585 on the same tag (docs/QR-TAGS-SPEC.md Section 2.2).
        //
        // NULL MEANS "ONLY IF IT IS SAFE", and it is the right setting. The
        // mount point is a real directory -- public_html/carl -- and Apache
        // maps URL paths onto filesystem paths case-sensitively, so
        // /CARL/T/AB7K4M is a web-server 404 that never reaches PHP. Carl
        // therefore upper-cases only when base_path is the domain root, where
        // there is no directory segment to get wrong.
        //
        // Set it to true ONLY after opening the upper-case URL in a browser
        // and seeing a page (deploy.md has the check). The print screen shows
        // which encoding is in force either way, so this is never silent, and
        // Carl\Qr\TagUrl's docblock is the whole argument.
        'uppercase_url' => null,
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

    // --- Mail (handoff Section 12.1) ------------------------------------
    // Nothing sends inline in a request: everything is written to
    // email_outbox and a cron drains it, the same discipline weather
    // follows and for the same reason -- a third-party outage must not be
    // able to make a page slow or 500 (Phase 3 handoff Section 4.1).
    //
    // driver 'none' is the state until the owner creates the mailbox
    // (Section 12.1). Mail still queues; the drain leaves it queued and
    // says so, so nothing is lost and nothing is sent twice when the
    // credentials arrive.
    'mail' => [
        'driver'     => 'none',          // 'smtp' | 'api' | 'none'; set in local.php
        'from_email' => 'carl@reshiftmanager.com',
        'from_name'  => 'Carl The Garden Helper',
        'reply_to'   => null,

        'smtp' => [
            // "Connect Devices" in cPanel Email Accounts prints these.
            'host'       => 'mail.reshiftmanager.com',
            'port'       => 465,
            'encryption' => 'tls',       // 'tls' (implicit, 465) | 'starttls' (587)
            'username'   => '',          // config/local.php
            'password'   => '',          // config/local.php
            'timeout'    => 20,
        ],

        'api' => [
            'url' => 'https://api.brevo.com/v3/smtp/email',
            'key' => '',                 // config/local.php
        ],

        // Bounded retries. A mail server that is down is down; five tries
        // over roughly two hours is the whole budget, then the row is failed
        // and /status says so.
        'max_attempts'    => 5,
        'retry_minutes'   => [2, 10, 30, 120],
        'batch'           => 25,         // rows per drain
        'retention_days'  => 30,         // sent rows are pruned; failed ones stay
    ],

    // --- Recommendations (Phase 5 handoff Section 3.1) -------------------
    // The Claude analysis. Same discipline as weather and mail and for the
    // same reason: a page queues an `analysis` row and returns, and a cron
    // makes the call. Nothing here may be reached from a request.
    //
    // The API KEY IS NOT HERE AND MUST NEVER BE. It goes in
    // config/local.php, which is gitignored and lives outside public_html
    // (hosting Section 6.4). With no key, requests queue and wait -- exactly
    // as mail does before the mailbox exists.
    'analysis' => [
        'api' => [
            'url' => 'https://api.anthropic.com/v1/messages',
            'key' => '',                 // config/local.php, NEVER here
        ],

        'model' => 'claude-opus-5',

        // What a million tokens costs, per model, for the admin page that
        // says what the month came to (Phase 6 handoff Section 3.5).
        //
        // A LOCAL ESTIMATE, and the page labels it as one. Nothing here is
        // fetched -- a price list is not something to put on a request path,
        // and a stale number shown as a fact is worse than one shown as an
        // estimate. Checked 2026-08-31 against the published rates; when they
        // move, this is the one place to edit.
        'prices' => [
            'claude-opus-5'      => ['input' => 5.00, 'output' => 25.00],
            'claude-fable-5'     => ['input' => 10.00, 'output' => 50.00],
            'claude-sonnet-5'    => ['input' => 2.00, 'output' => 10.00],
            'claude-haiku-4-5'   => ['input' => 1.00, 'output' => 5.00],
        ],

        // Effort is the first cost lever and it is left at the model's own
        // default ('high'). Set 'low' or 'medium' here if the bill matters
        // more than the depth of the answer.
        'effort' => '',

        // Around 700 words of prose, with headroom. Small on purpose: the
        // request is not streamed (see ClaudeClient), so the whole answer has
        // to arrive inside http_timeout.
        'max_tokens' => 2000,

        // Longer than the weather client's 20 s: an analysis is one long
        // request, not a series of short ones. The CLI cron has no execution
        // ceiling; the browser twin has 30 s (hosting Section 4) and passes
        // its own budget instead of relying on this.
        'http_timeout' => 120,

        // --- What is sent -------------------------------------------------
        // Measured 2026-08-31: a five-year account's /export/claude.json is
        // 3.3 MB, roughly 900,000 tokens, and 93% of it is the raw event log
        // and the daily weather (deploy.md Section 0.9). These three bound
        // the analysis document instead; Carl\Analysis\Document says how.
        'days'            => 365,        // the window, back from the user's own today
        'max_narratives'  => 60,         // notes sent verbatim, most recent first
        'max_plantings'   => 400,
        // A tripwire, not a target. If a built document exceeds this the
        // request fails permanently and says so, rather than being sent.
        'max_document_bytes' => 1048576,

        // --- The queue ----------------------------------------------------
        'batch'          => 3,           // requests per drain
        'max_per_day'    => 3,           // per user; every one of these is money
        'max_attempts'   => 4,
        'retry_minutes'  => [5, 30, 180],
        'lease_minutes'  => 10,          // past this, a 'sending' row is a dead process
        'retention_days' => 365,         // answers are kept a season; failures stay
    ],

    // --- The MCP server (Phase 16; Phase 15 handoff Section 3.1) ---------
    // POST /mcp, read-only, one bearer token per machine, minted on the
    // Connect Claude Code screen under Reports. Nothing here is secret.
    'mcp' => [
        // What initialize reports as the server version.
        'version' => '1.0',
        // Per token. The login limiter's shape: loose, because a locked-out
        // conversation is a worse failure than a busy one on a hobby tool,
        // and every call is one ordinary request with a statement count.
        'calls_per_minute' => 60,
        // A tool result over this is refused with a message saying how to
        // narrow it. The raw export of a five-year account is 3.3 MB and
        // 918,000 tokens; a tool that returned it would have failed.
        'max_response_bytes' => 262144,
        // Origins beyond the site's own that may call the endpoint from a
        // browser context. Claude Code sends no Origin at all; leave empty.
        'allowed_origins' => [],
    ],

    // --- The watering timer, and the phone it reaches (Phase 16) ---------
    'timers' => [
        'max_minutes' => 720,        // twelve hours; past that it is not a timer
        'batch'       => 50,         // rows per cron run
    ],
    'push' => [
        // Who a push service writes to about a misbehaving sender (RFC
        // 8292). Apple requires a mailto: or an https URL here.
        'subject' => 'mailto:carl@reshiftmanager.com',
        // How long the push service holds an undelivered message for a
        // phone that is off. An hour: a "your timer is done" that arrives
        // the next morning is worse than none.
        'ttl'     => 3600,
    ],

    // --- Crop rotation (Phase 5 handoff Section 3.4) ---------------------
    // How far back a bed's history still counts against planting the same
    // family in it again. Three years is the conventional rotation for the
    // families that need one; it is a nudge on the form, never a block.
    'rotation' => [
        'years' => 3,
    ],

    // --- Account invitations (Phase 5 handoff Section 3.5) ---------------
    // How long a set-password link in an email stays usable. Short enough
    // that a forwarded or archived message is not a standing credential;
    // long enough to survive a weekend.
    'invite' => [
        'lifetime_days' => 7,
    ],

    // --- Display --------------------------------------------------------
    // Store SI, convert at display (weather.md Section 6.3).
    'units' => 'us',   // 'us' => F / in / mph ; 'si' => C / mm / km/h
];
