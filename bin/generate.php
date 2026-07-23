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
    if (($cfg['url'] ?? '') === '' || ($cfg['app_token'] ?? '') === '') {
        throw new RuntimeException('Not configured yet. Open /setup.php (or set GLPI_URL/GLPI_APP_TOKEN).');
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
