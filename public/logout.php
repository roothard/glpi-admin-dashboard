<?php
/** logout.php — close the GLPI session and destroy the PHP session. @license MIT */
require dirname(__DIR__) . '/lib.php';
panel_session();
if (!empty($_SESSION['glpi_token'])) {
    glpi_fetch('/killSession', [], $_SESSION['glpi_token']);
}
$_SESSION = [];
session_destroy();
header('Content-Type: application/json');
echo json_encode(['ok' => true]);
