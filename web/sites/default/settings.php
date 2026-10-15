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
  // Google Drive sync (kb-chat:drive-sync). Two service accounts decide each
  // file's tier: the staff one is only in allemployees@, the management one
  // only in mgtteam@. Their JSON keys are sensitive Upsun variables.
  'drive' => [
    'accounts' => [
      'staff' => getenv('GOOGLE_DRIVE_SA_JSON'),
      'management' => getenv('GOOGLE_DRIVE_MGMT_SA_JSON'),
    ],
    // Shared drive names or IDs to leave out of the chat.
    'exclude_drives' => [],
    // Larger files are skipped.
    'max_file_bytes' => 25 * 1024 * 1024,
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
