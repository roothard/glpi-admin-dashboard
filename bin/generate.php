<?php
/**
 * generate.php — CLI entry point. Reads config, pulls GLPI over REST, writes data.json.
 *
 * Usage:
 *   php bin/generate.php [--env=/path/to/.env] [--out=/path/to/data.json]
 *
 * Intended to run from cron (or the Docker scheduler). Exit code 0 on success.
 *
 * @license MIT
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require __DIR__ . '/../src/Config.php';
require __DIR__ . '/../src/GlpiClient.php';
require __DIR__ . '/../src/DashboardGenerator.php';

// --- args ---
$opts = getopt('', ['env::', 'out::']);
$envFile = $opts['env'] ?? (getenv('DASHBOARD_ENV') ?: __DIR__ . '/../.env');

try {
    $cfg = Config::load(is_file($envFile) ? $envFile : null);
    if (!empty($cfg['timezone'])) {
        @date_default_timezone_set($cfg['timezone']);
    }
    $out = $opts['out'] ?? $cfg['output'];

    $client = new GlpiClient($cfg);
    $gen    = new DashboardGenerator($client, $cfg);

    $t0 = microtime(true);
    [$projects, $kb] = $gen->run($out);
    $ms = round((microtime(true) - $t0) * 1000);

    fwrite(STDOUT, sprintf(
        "OK — %d projects, %d KB linked → %s (%d ms)\n",
        $projects, $kb, $out, $ms
    ));
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
