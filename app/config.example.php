<?php
// Configuration template. Copy it as google-ads-mcp-php-data/config.php (in the private directory, outside the web root; NOT in git).
// Dev: generate it with tools/make-config.php. Comments name the matching variables of the original Node version's config.example.env.
return [
    // ---- Admin panel (one admin, passwordless login via an e-mail link) ----
    // Starting address. After the first login you change it in the Settings tab (SMTP is there too).
    // Until SMTP is set up, the login link goes to the file google-ads-mcp-php-data/logs/login-link.txt.
    'admin_email' => 'admin@example.com',

    // ---- Server ----
    // PUBLIC_BASE_URL: REQUIRED. Public URL without a trailing "/" (with the subdirectory, if the app lives in one). Links in e-mails and OAuth metadata are built from it, never from the Host header.
    'public_base_url' => 'https://example.com',
    // SECRET_KEY: key for encrypting secrets in the database, base64 of 32 bytes (openssl rand -base64 32). Changing it invalidates the stored Google tokens.
    'secret_key' => '',
    'debug' => false,               // true only locally: shows PHP errors
    'events_retention_days' => 14,  // EVENTS_RETENTION_DAYS: how many days to keep the event log
    'plan_ttl_hours' => 24,         // PLAN_TTL_HOURS: validity of prepared plans

    // ---- Google sign-in (OAuth) ----
    // Redirect URI in Google Cloud Console: {public_base_url}/oauth/google/callback
    'google_client_id' => '',
    'google_client_secret' => '',
    'allowed_email' => 'owner@example.com',   // ALLOWED_EMAIL: the only Google account that may connect

    // ---- MCP clients ----
    'app_clients' => ['claude-ai'],  // APP_CLIENTS: clients with the MCP Apps window (a client_id prefix, e.g. claude-ai-xx
    // Plan approval. false (default, recommended): the approve_plan MCP tool does not exist and the preview window is not attached;
    // a plan is approved only on its page, by the administrator logged in (e-mail link). true: clients listed in app_clients get
    // approve_plan, i.e. the MODEL can approve its own plans (see docs/adr/0005-zatwierdzanie-planow.md). Keep it false unless you accept that.
    'allow_tool_approve' => false,
    'static_token_ttl_days' => 180,  // lifetime of a permanent Bearer token (client type claude-code-token); renew it in Connections
    // Signing key of upload/plan links, derived from secret_key (rotating it does NOT touch encrypted secrets).
    // To rotate: raise link_key_version; also raise link_key_min_version to the same number to revoke links signed with older keys.
    'link_key_version' => 1,
    'link_key_min_version' => 1,

    // ---- Google Ads ----
    'ads_customer_id' => '',         // ADS_CUSTOMER_ID (hyphens allowed)
    'ads_api_version' => 'v26',      // ADS_API_VERSION
    'ads_developer_token' => '',     // optional: the developer-token header (Node does not send it)
    'ads_login_customer_id' => '',   // optional: the login-customer-id header (manager account)
    'max_daily_budget_pln' => 30,    // MAX_DAILY_BUDGET_PLN: a plan with a higher daily budget is rejected
    // AUTO_EXECUTE: true = prepare_* executes the plan right after a successful validateOnly; false = the plan waits for approval.
    // On dev ALWAYS false.
    'auto_execute' => false,
    'allow_preview_only' => false,   // ALLOW_PREVIEW_ONLY: whether the previewOnly parameter is honored
    'account_time_zone' => 'Europe/Warsaw', // ACCOUNT_TIME_ZONE

    // ---- GA4 ----
    'ga4_property_id' => '',         // GA4_PROPERTY_ID (empty = ga4_report says it is not configured)

    // ---- Company and landing pages ----
    'allowed_url_hosts' => [],       // ALLOWED_URL_HOSTS: allowed domains of landing page and image URLs (www. is accepted automatically)
    'landing_path_prefix' => '/lp2/', // LANDING_PATH_PREFIX
    'lp2_forbidden_phrases' => [],   // LP2_FORBIDDEN_PHRASES: phrases forbidden on the landing page
    'lp2_thankyou_path' => '',       // LP2_THANKYOU_PATH
    'upload_password' => '',         // UPLOAD_PASSWORD: a fixed upload page (min. 12 characters); empty = token links only
    'youtube_api_key' => '',         // YOUTUBE_API_KEY (optional)
    'lp2_admin_api_url' => '',       // LP2_ADMIN_API_URL
    'lp2_admin_api_token' => '',     // LP2_ADMIN_API_TOKEN
    'conversion_action_name' => 'Website lead form', // CONVERSION_ACTION_NAME
    'conversion_action_id' => '',    // CONVERSION_ACTION_ID
    'conversion_tag_settings_url' => '', // CONVERSION_TAG_SETTINGS_URL

    // ---- Default location of new campaigns (example: Warsaw) ----
    'default_geo_lat' => 52.2297,    // DEFAULT_GEO_LAT
    'default_geo_lng' => 21.0122,    // DEFAULT_GEO_LNG
    'default_geo_radius_km' => 20,   // DEFAULT_GEO_RADIUS_KM
    'default_geo_label' => 'Warsaw', // DEFAULT_GEO_LABEL
];
