<?php
/**
 * generate.php — CLI entry point. Reads config (config/settings.json, with env
 * overrides), pulls GLPI over REST, writes data-cache.json.
 *
 * Usage: php bin/generate.php [--out=/path/to/data-cache.json]
 * Intended to run from cron. Exit 0 on success.
 * @license MIT
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

require __DIR__ . '/../src/Settings.php';
require __DIR__ . '/../src/GlpiClient.php';
require __DIR__ . '/../src/DashboardGenerator.php';

$opts = getopt('', ['out::', 'config::']);

try {
    $cfg = Settings::flat(Settings::load($opts['config'] ?? null));
    // Autenticación válida = App-Token (API legacy) o client_id OAuth (API v2).
    $hasAuth = ($cfg['app_token'] ?? '') !== '' || ($cfg['oauth_client_id'] ?? '') !== '';
    if (($cfg['url'] ?? '') === '' || !$hasAuth) {
        throw new RuntimeException('Not configured yet. Open /setup.php (or set GLPI_URL + App-Token for the legacy API, or the OAuth client credentials for the v2 API).');
    }
    if (!empty($cfg['timezone'])) { @date_default_timezone_set($cfg['timezone']); }
    $out = $opts['out'] ?? $cfg['output'];

    $client = new GlpiClient($cfg);
    $gen    = new DashboardGenerator($client, $cfg);

    $t0 = microtime(true);
    [$projects, $kb] = $gen->run($out);
    $ms = round((microtime(true) - $t0) * 1000);

    fwrite(STDOUT, sprintf("OK — %d projects, %d KB linked → %s (%d ms)\n", $projects, $kb, $out, $ms));
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
