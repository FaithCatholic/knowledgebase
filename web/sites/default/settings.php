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
