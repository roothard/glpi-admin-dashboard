<?php
/**
 * data-cumplimiento.php — Compliance panel data.
 *
 * For each process defined in config/processes.json it looks up its EVIDENCE:
 * real GLPI tickets matched by category id or by text contained in the title,
 * queried with the logged-in user's session. The panel does not verify the
 * backups — it verifies that somebody verified the backups.
 *
 * States: ok (last done within period+grace, clean result) / ok_find (al día
 * con hallazgos) / ok_crit (al día, resultado crítico) / run (open ticket, not
 * due) / late (due date passed) / nodata (never happened). The result tier is
 * read from the ticket's Solution type (search field 23). The evaluation itself
 * lives in cmp-lib.php, shared with data-normas.php so both agree.
 * @license MIT
 */
require dirname(__DIR__) . '/cmp-lib.php'; // también carga lib.php
panel_session();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['glpi_token'])) { http_response_code(401); echo json_encode(['auth' => false]); exit; }
$tok = $_SESSION['glpi_token'];

$pfile = dirname(__DIR__) . '/config/processes.json';
$pcfg  = is_file($pfile) ? (json_decode((string)file_get_contents($pfile), true) ?: []) : [];
$procs = array_values($pcfg['processes'] ?? []);

$now = time();
$result = []; $techIds = [];

foreach ($procs as $p) {
    $ents = $p['entities'] ?? [];
    if ($ents) { // una fila por entidad donde aplica el proceso
        foreach ($ents as $e) {
            $result[] = evalProc($p, (int)$e['id'], (string)($e['name'] ?? ''), $now, $techIds, $tok);
        }
    } else {     // sin entidades: evaluación global (todo lo que ve el usuario)
        $result[] = evalProc($p, null, '', $now, $techIds, $tok);
    }
}

cmpResolveTechs($result, $techIds, $tok); // ids de técnico -> nombres

$cnt  = fn($s) => count(array_filter($result, fn($r) => $r['state'] === $s));
$ok   = $cnt('ok') + $cnt('run');               // al día (limpio) o en curso
$find = $cnt('ok_find') + $cnt('ok_crit');      // ejecutado pero con hallazgos
$late = $cnt('late');
$soon = count(array_filter($result, fn($r) => $r['dueIn'] !== null && $r['dueIn'] >= 0 && $r['dueIn'] <= 7 && $r['state'] !== 'late'));

echo json_encode([
    'glpi_url' => rtrim((string)(cfg()['glpi']['url'] ?? ''), '/'),
    'user'     => $_SESSION['user'] ?? '',
    'canEdit'  => !empty($_SESSION['isAdmin']),
    'procs'    => $result,
    'kpis'     => ['total' => count($result), 'ok' => $ok, 'find' => $find, 'late' => $late, 'soon' => $soon],
], JSON_UNESCAPED_UNICODE);
