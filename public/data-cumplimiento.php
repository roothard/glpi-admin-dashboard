<?php
/**
 * data-cumplimiento.php — Compliance panel data.
 *
 * For each process defined in config/processes.json it looks up its EVIDENCE:
 * real GLPI tickets matched by category id or by text contained in the title,
 * queried with the logged-in user's session. The panel does not verify the
 * backups — it verifies that somebody verified the backups.
 *
 * States: ok (last done within period+grace) / run (open ticket, not due) /
 * late (due date passed) / nodata (never happened). The cycle history bar is
 * what turns a traffic light into an audit argument.
 * @license MIT
 */
require dirname(__DIR__) . '/lib.php';
panel_session();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['glpi_token'])) { http_response_code(401); echo json_encode(['auth' => false]); exit; }
$tok = $_SESSION['glpi_token'];

const CF      = ['id' => 2, 'title' => 1, 'status' => 12, 'date' => 15, 'close' => 16, 'solve' => 17, 'cat' => 7, 'tech' => 5, 'ent' => 80];
const PERIODS = ['daily' => 1, 'weekly' => 7, 'monthly' => 30, 'quarterly' => 91, 'halfyearly' => 182, 'yearly' => 365];
const CYCLES  = 12; // segments in the history bar

$pfile = dirname(__DIR__) . '/config/processes.json';
$pcfg  = is_file($pfile) ? (json_decode((string)file_get_contents($pfile), true) ?: []) : [];
$procs = array_values($pcfg['processes'] ?? []);

/** Paginated /search/Ticket limited to what the history needs. */
function cmpSearch(array $criteria, string $tok): array
{
    $out = []; $start = 0; $page = 250;
    do {
        [$code, $body] = glpi_fetch('/search/Ticket', [
            'criteria'     => $criteria,
            'forcedisplay' => array_values(CF),
            'range'        => $start . '-' . ($start + $page - 1),
            'sort'         => CF['date'], 'order' => 'DESC',
        ], $tok);
        if ($code === 401) { $_SESSION = []; http_response_code(401); echo json_encode(['auth' => false]); exit; }
        if ($code >= 400 || !is_array($body)) { break; }
        $rows = $body['data'] ?? [];
        foreach ($rows as $r) { if (is_array($r)) { $out[] = $r; } }
        $got = count($rows); $start += $got;
        $total = (int)($body['totalcount'] ?? 0);
    } while ($got === $page && $start < $total && $start < 1000);
    return $out;
}

function cv($row, $f) { $v = $row[(string)CF[$f]] ?? ($row[CF[$f]] ?? null);
    if (is_array($v)) { $v = implode(', ', array_filter(array_map('strval', $v))); }
    return $v === '' ? null : $v; }

$now = time();
$result = []; $techIds = [];

// Evalúa un proceso para UN alcance de entidad (o global si $entId === null).
// Devuelve una fila del panel + acumula los ids de técnico a resolver.
function evalProc(array $p, ?int $entId, string $entName, int $now, array &$techIds): array
{
    $P     = (PERIODS[$p['every']] ?? 30) * 86400;
    $grace = (int)($p['grace_days'] ?? 0) * 86400;
    $base = [($p['match']['by'] ?? 'category') === 'title'
        ? ['field' => CF['title'], 'searchtype' => 'contains', 'value' => (string)$p['match']['value']]
        : ['field' => CF['cat'], 'searchtype' => 'equals', 'value' => (int)$p['match']['value']]];
    if ($entId !== null && $entId > 0) {
        $base[] = ['link' => 'AND', 'field' => CF['ent'], 'searchtype' => 'equals', 'value' => $entId];
    }

    global $tok;
    $since = date('Y-m-d H:i:s', $now - CYCLES * $P);
    $old = cmpSearch(array_merge($base, [
        ['link' => 'AND', 'field' => CF['status'], 'searchtype' => 'equals', 'value' => 'old'],
        ['link' => 'AND', 'field' => CF['date'], 'searchtype' => 'morethan', 'value' => $since],
    ]), $tok);
    $open = cmpSearch(array_merge($base, [
        ['link' => 'AND', 'field' => CF['status'], 'searchtype' => 'equals', 'value' => 'notold'],
    ]), $tok);

    $done = [];
    foreach ($old as $r) {
        $d = cv($r, 'solve') ?: cv($r, 'close') ?: cv($r, 'date');
        $ts = $d ? strtotime($d) : null;
        if ($ts) { $done[] = $ts; }
    }
    rsort($done);
    $lastDone = $done[0] ?? null;

    $due = $lastDone ? $lastDone + $P + $grace : null;
    if ($lastDone === null && !$open) { $state = 'nodata'; }
    elseif ($lastDone === null)       { $state = 'run'; }
    elseif ($now > $due)              { $state = 'late'; }
    elseif ($open)                    { $state = 'run'; }
    else                              { $state = 'ok'; }

    $cycles = [];
    for ($i = CYCLES - 1; $i >= 0; $i--) {
        $from = $now - ($i + 1) * $P; $to = $now - $i * $P;
        $hit = false;
        foreach ($done as $ts) { if ($ts >= $from && $ts < $to) { $hit = true; break; } }
        $cycles[] = $hit ? 1 : 0;
    }

    $ev = [];
    foreach (array_merge($open, array_slice($old, 0, 10)) as $r) {
        if (count($ev) >= 12) break;
        $tech = cv($r, 'tech');
        foreach (explode(',', (string)$tech) as $t) { $t = trim($t); if (ctype_digit($t)) { $techIds[(int)$t] = 1; } }
        $ev[] = [
            'id'   => (int)cv($r, 'id'), 't' => (string)cv($r, 'title'),
            's'    => (int)cv($r, 'status'), 'date' => cv($r, 'date'),
            'done' => cv($r, 'solve') ?: cv($r, 'close'), 'tech' => $tech,
        ];
    }

    return [
        'id'    => $p['id'] . ($entId ? '@' . $entId : ''),
        'name'  => $p['name'], 'every' => $p['every'], 'grace' => (int)($p['grace_days'] ?? 0),
        'ent'   => $entName,
        'owner' => (string)($p['owner_name'] ?? ''), 'grp' => (string)($p['group_name'] ?? ''),
        'state' => $state,
        'last'  => $lastDone ? date('Y-m-d H:i', $lastDone) : null,
        'days'  => $lastDone ? (int)floor(($now - $lastDone) / 86400) : null,
        'dueIn' => $due ? (int)ceil(($due - $now) / 86400) : null,
        'cycles' => $cycles,
        'ev'     => $ev,
    ];
}

foreach ($procs as $p) {
    $ents = $p['entities'] ?? [];
    if ($ents) { // una fila por entidad donde aplica el proceso
        foreach ($ents as $e) {
            $result[] = evalProc($p, (int)$e['id'], (string)($e['name'] ?? ''), $now, $techIds);
        }
    } else {     // sin entidades: evaluación global (todo lo que ve el usuario)
        $result[] = evalProc($p, null, '', $now, $techIds);
    }
}

// Resolve technician ids -> names (search returns numeric actor ids)
if ($techIds) {
    $map = []; $start = 0; $page = 250; $bulkOk = false;
    do {
        [$c, $b] = glpi_fetch('/User', ['range' => $start . '-' . ($start + $page - 1)], $tok);
        if ($c >= 400 || !is_array($b)) break;
        $bulkOk = true;
        $rows = (isset($b[0]) || $b === []) ? $b : [$b];
        foreach ($rows as $u) {
            if (!is_array($u) || !isset($u['id'])) continue;
            $n = trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? ''));
            $map[(int)$u['id']] = $n !== '' ? $n : (string)($u['name'] ?? $u['id']);
        }
        $got = count($rows); $start += $got;
    } while ($got === $page && $start < 2000);
    if (!$bulkOk) {
        foreach (array_slice(array_keys($techIds), 0, 50) as $id) {
            [$c, $u] = glpi_fetch('/User/' . $id, [], $tok);
            if ($c === 200 && is_array($u)) {
                $n = trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? ''));
                $map[$id] = $n !== '' ? $n : (string)($u['name'] ?? $id);
            }
        }
    }
    foreach ($result as &$pr) {
        foreach ($pr['ev'] as &$e) {
            if ($e['tech'] === null) continue;
            $names = [];
            foreach (explode(',', (string)$e['tech']) as $t) {
                $t = trim($t); if ($t === '') continue;
                $names[] = (ctype_digit($t) && isset($map[(int)$t])) ? $map[(int)$t] : $t;
            }
            $e['tech'] = $names ? implode(', ', $names) : null;
        }
        unset($e);
    }
    unset($pr);
}

$late = count(array_filter($result, fn($r) => $r['state'] === 'late'));
$ok   = count(array_filter($result, fn($r) => $r['state'] === 'ok' || $r['state'] === 'run'));
$soon = count(array_filter($result, fn($r) => $r['dueIn'] !== null && $r['dueIn'] >= 0 && $r['dueIn'] <= 7 && $r['state'] !== 'late'));

echo json_encode([
    'glpi_url' => rtrim((string)(cfg()['glpi']['url'] ?? ''), '/'),
    'user'     => $_SESSION['user'] ?? '',
    'canEdit'  => !empty($_SESSION['isAdmin']),
    'procs'    => $result,
    'kpis'     => ['total' => count($result), 'ok' => $ok, 'late' => $late, 'soon' => $soon],
], JSON_UNESCAPED_UNICODE);
