<?php
/**
 * config.php — public (non-secret) configuration for the front-end:
 * branding, enabled modules and their links, default language.
 * Never exposes tokens or DB credentials.
 * @license MIT
 */
require dirname(__DIR__) . '/src/Settings.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(Settings::publicConfig(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
