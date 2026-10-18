<?php
// Automatically generated include for settings managed by ddev.
if (getenv('IS_DDEV_PROJECT') == 'true' && file_exists(__DIR__ . '/settings.ddev.php')) {
  include __DIR__ . '/settings.ddev.php';
}

/**
 * Load local development override configuration, if available.
 *
 * Create a settings.local.php file to override variables on secondary (staging,
 * development, etc.) installations of this site.
 *
 * Typical uses of settings.local.php include:
 * - Disabling caching.
 * - Disabling JavaScript/CSS compression.
 * - Rerouting outgoing emails.
 *
 * Keep this code block at the end of this file to take full effect.
 */

 // settings.local.php is committed for DDEV, so skip it on Upsun: it shows
 // verbose errors and turns off CSS/JS aggregation.
 if (!getenv('PLATFORM_APPLICATION') && file_exists($app_root . '/' . $site_path . '/settings.local.php')) {
   include $app_root . '/' . $site_path . '/settings.local.php';
 }

// Upsun configuration
if (getenv('PLATFORM_APPLICATION') && file_exists(__DIR__ . '/settings.upsun.php')) {
  include __DIR__ . '/settings.upsun.php';
}
// Microsoft 365 integration variables below
//$settings['o365']['api_settings']['client_id']     = getenv('O365_CLIENT_ID');
//$settings['o365']['api_settings']['tenant_id']        = getenv('O365_TENANT_ID');
//$settings['o365']['api_settings']['client_secret'] = getenv('O365_CLIENT_SECRET');

$settings['o365'] = [
  'default' => [
    'client_id' => getenv('O365_CLIENT_ID'),
    'client_secret' => getenv('O365_CLIENT_SECRET'),
    'tenant_id' => getenv('O365_TENANT_ID'),
  ]
];
// Google sign-in (social_auth_google). GOOGLE_CLIENT_ID and
// GOOGLE_CLIENT_SECRET are Upsun variables without the "env:" prefix, so they
// arrive in PLATFORM_VARIABLES (base64-encoded JSON) rather than as
// environment variables; either form works. The values stay out of config
// and git. Saving the Google settings form would store them in config again,
// so change them on Upsun instead.
$upsun_variables = getenv('PLATFORM_VARIABLES') ? (json_decode(base64_decode(getenv('PLATFORM_VARIABLES')), TRUE) ?: []) : [];
foreach (['client_id' => 'GOOGLE_CLIENT_ID', 'client_secret' => 'GOOGLE_CLIENT_SECRET'] as $key => $name) {
  $value = getenv($name) ?: ($upsun_variables[$name] ?? $upsun_variables['env:' . $name] ?? '');
  if ($value !== '') {
    $config['social_auth_google.settings'][$key] = $value;
  }
}
unset($upsun_variables, $key, $name, $value);

// Upsun dashboard (upsun_dashboard module). UPSUN_API_TOKEN is a sensitive
// project-level variable on Upsun; see the module README.
$settings['upsun_dashboard'] = [
  'api_token' => getenv('UPSUN_API_TOKEN'),
  // Seconds between automatic refreshes on cron.
  'refresh_interval' => 3600,
];
// Chargebee bulk tools (chargebee_tools module). The API keys are sensitive
// project-level variables on Upsun; see the module README.
$settings['chargebee_tools'] = [
  'sites' => [
    'test' => [
      'site' => 'faithcatholic-test',
      'api_key' => getenv('CHARGEBEE_TEST_API_KEY'),
    ],
    'live' => [
      'site' => 'faithcatholic',
      'api_key' => getenv('CHARGEBEE_LIVE_API_KEY'),
    ],
  ],
  // Seconds between Chargebee API calls.
  'delay' => 1,
];
// Vector index for the kb chat (ai_vdb_provider_postgres). Upsun's
// .environment exports VECTORDB_* from the vectordb relationship; the password
// comes from the vectordb_password key, which reads VECTORDB_PASSWORD.
if (getenv('VECTORDB_HOST')) {
  $config['ai_vdb_provider_postgres.settings']['host'] = getenv('VECTORDB_HOST');
  $config['ai_vdb_provider_postgres.settings']['port'] = (int) getenv('VECTORDB_PORT');
  $config['ai_vdb_provider_postgres.settings']['username'] = getenv('VECTORDB_USERNAME');
  $config['ai_vdb_provider_postgres.settings']['default_database'] = getenv('VECTORDB_PATH');
  $config['ai_vdb_provider_postgres.settings']['password'] = 'vectordb_password';
}
// kb chat cost tracking (kb_chat module); see the module README. The keys are
// sensitive Upsun variables.
$settings['kb_chat'] = [
  // Admin API key (sk-ant-admin...), for Anthropic's cost report.
  'anthropic_admin_key' => getenv('ANTHROPIC_ADMIN_API_KEY'),
  // Optional: only count this Anthropic workspace's costs (wrkspc_...).
  'anthropic_workspace_id' => getenv('ANTHROPIC_WORKSPACE_ID'),
  // Shared secret in the Google Pub/Sub push URL.
  'google_billing_token' => getenv('GOOGLE_BILLING_PUSH_TOKEN'),
  // Seconds between Anthropic cost report refreshes on cron.
  'refresh_interval' => 3600,
  // Hosting costs in the KB costs block: the FAITH Flex organization's billing
  // profile (kb is its only project), as collected by upsun_dashboard.
  'upsun_billing_profile' => '01KMQZTS3V7K3WEKQRRHK87BA4',
  // The data warehouse query tool: BigQuery views curated for the chat. Queries
  // run as the Drive service accounts below, staff or management by the user's
  // permissions; each may only read its datasets.
  'warehouse' => [
    'project' => 'faith-data-warehouse',
    'location' => 'US',
    'datasets' => [
      'staff' => ['kb_chat'],
      'management' => ['kb_chat', 'kb_chat_management'],
    ],
    // BigQuery refuses queries that would scan more (1 GB costs about $0.006).
    'max_bytes' => 1000000000,
    'max_rows' => 200,
  ],
  // The Trello search tool: live, read-only, limited to these boards (names as
  // shown in Trello, or board IDs). The account behind the key and token must
  // be a member of them; TRELLO_API_KEY and TRELLO_TOKEN are sensitive
  // variables. "management" boards are added for users with the management
  // documents permission.
  'trello' => [
    'key' => getenv('TRELLO_API_KEY'),
    'token' => getenv('TRELLO_TOKEN'),
    'boards' => [
      'staff' => [
        'Strategic Planning 2025-2028',
        'Web & Digital Directory',
        'Web & Digital Hotsheet',
        'Parish Products',
        'Parish Products New Store (2022)',
        'Technology Hotsheet',
        'Catholic Schools/Integrated Marketing',
        'Catholic Magazines Development',
        'Digital Pub Production Schedule',
        'Digital Pub E-News and Social Media Planning',
        'Digital Publishing Development Tracker',
        'Product & Service Hotsheet',
        'Product & Service Operations Ideas',
        'Digital Subscription Stor-e-Board',
        'Digital Subscription Portal - Project Board',
        'Subscription Emails',
        'Custom Pub Clients',
        'Custom Pub Sales',
        'Web & Digital Strategic Plan',
        'Web & Digital Service Tiers',
        'Intern / Freelance Services',
      ],
      'management' => [],
    ],
  ],
  // The magazine archive search tool: catholicmagazines.org's archive_search_api
  // endpoint. MAGAZINE_ARCHIVE_TOKEN matches ARCHIVE_SEARCH_TOKEN there.
  'magazine_archive' => [
    'url' => 'https://catholicmagazines.org',
    'token' => getenv('MAGAZINE_ARCHIVE_TOKEN'),
    // Results per search.
    'limit' => 8,
  ],
  // FAITH publications on the mothership (faithmagazine project, Platform.sh):
  // its kb_search_api endpoint searches every domain. The token is
  // KB_SEARCH_TOKEN there and MOTHERSHIP_SEARCH_TOKEN here.
  'mothership' => [
    'url' => 'https://faithsyndicated.org',
    'token' => getenv('MOTHERSHIP_SEARCH_TOKEN'),
    // Results per search.
    'limit' => 8,
  ],
  // Google Drive sync (kb-chat:drive-sync). Two service accounts decide each
  // file's tier: the staff one is only in allemployees@, the management one
  // only in mgtteam@. Their JSON keys are sensitive Upsun variables.
  'drive' => [
    'accounts' => [
      'staff' => getenv('GOOGLE_DRIVE_SA_JSON'),
      'management' => getenv('GOOGLE_DRIVE_MGMT_SA_JSON'),
    ],
    // What to leave out of the chat (drives, folders, file names), how often
    // to list the drives and file size limits are set in the admin UI:
    // Configuration > Search > KB chat Google Drive (kb_chat.drive config).
    // Text beyond this is not indexed.
    'max_text_chars' => 300000,
  ],
  // USD per million tokens, for kb's live estimate. Keys match a model ID
  // exactly or as a prefix. Update when prices or models change.
  'prices' => [
    'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_read' => 0.20, 'cache_write' => 5.00],
    'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_read' => 0.20, 'cache_write' => 2.50],
    // Gemini embeddings (text input), paid tier. The Gemini module names
    // models with a "models/" prefix. https://ai.google.dev/gemini-api/docs/pricing
    'models/gemini-embedding-2' => ['input' => 0.20],
    'gemini-embedding-2' => ['input' => 0.20],
  ],
];
