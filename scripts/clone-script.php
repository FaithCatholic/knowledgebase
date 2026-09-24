<?php
// Repos cloned in during the build. Paths are relative to the app root.
$token = getenv('DASHBOARD_GITHUB_TOKEN');
$repos = [
    './web/slides' => 'git@github.com:mikejon-es/slides.git',
    // Kept outside web/ so it's only reachable through the design_dashboard module.
    // On Upsun, clone over HTTPS with a read-only token (the slides deploy key
    // can't be reused on another repo); locally, fall back to your own SSH key.
    './design_dashboard' => $token
        ? 'https://x-access-token:' . $token . '@github.com/FaithCatholic/design_dashboard.git'
        : 'git@github.com:FaithCatholic/design_dashboard.git',
];

foreach ($repos as $dir => $url) {
    if (!is_dir($dir)) {
        exec('git clone --depth=1 ' . escapeshellarg($url) . ' ' . escapeshellarg($dir) . ' 2>&1', $output, $status);
        if ($status !== 0) {
            // Report the directory, not the URL, so the token never lands in build logs.
            fwrite(STDERR, "Failed to clone into $dir\n");
            exit(1);
        }
        // Don't leave the token in the clone's git config.
        exec('git -C ' . escapeshellarg($dir) . ' remote remove origin');
    }
}
