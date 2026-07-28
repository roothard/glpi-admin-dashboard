<?php
/**
 * data-fichadas.php — OPTIONAL example module: field check-ins ("Fichadas").
 *
 * Reads "Visita técnica" tickets straight from the GLPI DB and scopes them by
 * the logged-in user's role (admin: all, supervisor: team, technician: own).
 * This is a domain-specific example (attendance/GPS visits) — remove it if you
 * only want the projects board. Requires DB_* in .env (direct read-only DB).
 *
 * @license MIT
 */
require dirname(__DIR__) . '/lib.php';
panel_session();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['glpi_token'])) { http_response_code(401); echo json_encode(['auth' => false]); exit; }

$dbc = cfg()['modules']['gps']['db'];
$db = @new mysqli($dbc['host'] ?? '', $dbc['user'] ?? '', $dbc['pass'] ?? '', $dbc['name'] ?? '');
if ($db->connect_errno) { http_response_code(500); echo json_encode(['error' => 'db']); exit; }
$db->set_charset('utf8mb4');

$me = (int)($_SESSION['glpiID'] ?? 0);
$isAdmin = !empty($_SESSION['isAdmin']);
$isSuper = !empty($_SESSION['isSuper']);

$where = "t.name LIKE 'Visita técnica%' AND t.is_deleted=0";
$scope = 'tecnico';
if ($isAdmin) {
    $scope = 'admin';
} elseif ($isSuper) {
    $scope = 'supervisor';
    $ids = [$me];
    $r = $db->query("SELECT id FROM glpi_users WHERE users_id_supervisor=$me");
    while ($x = $r->fetch_row()) { $ids[] = (int)$x[0]; }
    $where .= " AND t.users_id_recipient IN (" . implode(',', array_map('intval', $ids)) . ")";
} else {
    $where .= " AND t.users_id_recipient=$me";
}

// Team block for the "My team" panel (supervisor/admin): every technician in
// scope — checked in or not — with their last visit and current status.
$team = [];
if ($scope === 'supervisor' || $scope === 'admin') {
    if ($scope === 'supervisor') {
        $tw = "u.id IN (" . implode(',', array_map('intval', $ids)) . ")";
    } else {
        $tw = "(u.users_id_supervisor>0 OR EXISTS(SELECT 1 FROM glpi_tickets tt WHERE tt.users_id_recipient=u.id AND tt.name LIKE 'Visita técnica%' AND tt.is_deleted=0))";
    }
    $q = "SELECT u.id,
            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.firstname,''),' ',COALESCE(u.realname,''))),''),u.name) nombre,
            u.firstname, COALESCE(NULLIF(u.mobile,''),u.phone) tel,
            (SELECT email FROM glpi_useremails ue WHERE ue.users_id=u.id AND ue.is_default=1 LIMIT 1) email,
            MAX(t.date) last_in,
            MAX(CASE WHEN t.date>=CURDATE() THEN 1 ELSE 0 END) hoy,
            MAX(CASE WHEN t.date>=CURDATE() AND t.solvedate IS NULL AND t.closedate IS NULL THEN 1 ELSE 0 END) abierta
          FROM glpi_users u
          LEFT JOIN glpi_tickets t ON t.users_id_recipient=u.id AND t.name LIKE 'Visita técnica%' AND t.is_deleted=0
          WHERE u.is_deleted=0 AND u.is_active=1 AND u.name<>'glpi-system' AND $tw
          GROUP BY u.id ORDER BY nombre";
    if ($r = $db->query($q)) {
        while ($x = $r->fetch_assoc()) {
            $team[] = ['id' => (int)$x['id'], 'nombre' => $x['nombre'],
                       'nom1' => $x['firstname'] ?: strtok((string)$x['nombre'], ' '),
                       'tel' => $x['tel'], 'email' => $x['email'], 'last' => $x['last_in'],
                       'hoy' => ((int)$x['hoy']) === 1, 'abierta' => ((int)$x['abierta']) === 1];
        }
    }
}

$from = $_GET['from'] ?? '';
$to   = $_GET['to'] ?? '';
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where .= " AND t.date>='" . $db->real_escape_string($from) . " 00:00:00'"; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $where .= " AND t.date<='" . $db->real_escape_string($to) . " 23:59:59'"; }

$sql = "SELECT t.id,
          COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.firstname,''),' ',COALESCE(u.realname,''))),''),u.name) tecnico,
          COALESCE(NULLIF(e.completename,''),e.name) cliente,
          t.date entrada, COALESCE(t.solvedate,t.closedate) salida, t.status, t.content
        FROM glpi_tickets t
        LEFT JOIN glpi_users u ON u.id=t.users_id_recipient
        LEFT JOIN glpi_entities e ON e.id=t.entities_id
        WHERE $where ORDER BY t.date DESC LIMIT 2000";

$rows = [];
$r = $db->query($sql);
while ($x = $r->fetch_assoc()) {
    $lat = null; $lon = null;
    if (preg_match('/[?&]q=(-?\d+\.\d+),(-?\d+\.\d+)/', $x['content'] ?? '', $mm)) { $lat = $mm[1]; $lon = $mm[2]; }
    $dur = null;
    if ($x['entrada'] && $x['salida']) { $dur = max(0, strtotime($x['salida']) - strtotime($x['entrada'])); }
    $rows[] = ['id' => (int)$x['id'], 'tecnico' => $x['tecnico'], 'cliente' => $x['cliente'],
               'entrada' => $x['entrada'], 'salida' => $x['salida'], 'dur' => $dur, 'lat' => $lat, 'lon' => $lon];
}

echo json_encode(['user' => $_SESSION['user'], 'scope' => $scope, 'team' => $team, 'fichadas' => $rows], JSON_UNESCAPED_UNICODE);
