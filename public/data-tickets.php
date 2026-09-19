<?php
/**
 * data-tickets.php — tickets dashboard data, scoped to the LOGGED-IN user
 * (GLPI decides what each user can see: technicians their queue, admins all).
 * Always returns every open ticket, plus solved/closed ones touched within
 * the requested window (?days=7|30|90|0, 0 = full history). SLA traffic
 * lights are computed from the ticket deadlines (time_to_own / time_to_resolve)
 * so no fragile SLA-name lookups are needed.
 * @license MIT
 */
require dirname(__DIR__) . '/lib.php';
panel_session();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['glpi_token'])) { http_response_code(401); echo json_encode(['auth' => false]); exit; }
$tok  = $_SESSION['glpi_token'];
$days = isset($_GET['days']) ? max(0, (int)$_GET['days']) : 30;

// Search-engine field ids (GLPI 10 core Ticket options)
const F = [
    'id' => 2, 'title' => 1, 'status' => 12, 'prio' => 3, 'date' => 15, 'mod' => 19,
    'solve' => 17, 'close' => 16, 'ttr' => 18, 'tto' => 155, 'cat' => 7, 'ent' => 80,
    'req' => 4, 'tech' => 5, 'grp' => 8, 'took' => 150, // 150 = take-into-account delay (s)
    'type' => 14, // 1 = incidencia, 2 = solicitud
];

/** Run one /search/Ticket query (criteria array), following pagination. */
function searchTickets(array $criteria, string $tok): array
{
    $force = array_values(F);
    $out = []; $start = 0; $page = 250;
    do {
        [$code, $body] = glpi_fetch('/search/Ticket', [
            'criteria'     => $criteria,
            'forcedisplay' => $force,
            'range'        => $start . '-' . ($start + $page - 1),
            'sort'         => F['date'], 'order' => 'DESC',
        ], $tok);
        if ($code === 401) { $_SESSION = []; http_response_code(401); echo json_encode(['auth' => false]); exit; }
        if ($code >= 400 || !is_array($body)) { break; }
        $rows = $body['data'] ?? [];
        foreach ($rows as $r) { if (is_array($r)) { $out[] = $r; } }
        $got = count($rows); $start += $got;
        $total = (int)($body['totalcount'] ?? 0);
    } while ($got === $page && $start < $total && $start < 5000);
    return $out;
}

$openRows = searchTickets([
    ['field' => F['status'], 'searchtype' => 'equals', 'value' => 'notold'],
], $tok);

// Ventana para resueltos/cerrados: por presets (?days) o rango explícito (?from/?to, por fecha de creación)
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : null;
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']   ?? '') ? $_GET['to']   : null;
$oldCriteria = [['field' => F['status'], 'searchtype' => 'equals', 'value' => 'old']];
if ($from || $to) {
    if ($from) $oldCriteria[] = ['link' => 'AND', 'field' => F['date'], 'searchtype' => 'morethan', 'value' => $from . ' 00:00:00'];
    if ($to)   $oldCriteria[] = ['link' => 'AND', 'field' => F['date'], 'searchtype' => 'lessthan', 'value' => $to . ' 23:59:59'];
} elseif ($days > 0) {
    $oldCriteria[] = ['link' => 'AND', 'field' => F['mod'], 'searchtype' => 'morethan', 'value' => date('Y-m-d H:i:s', time() - $days * 86400)];
}
$oldRows = searchTickets($oldCriteria, $tok);

/** Normalize a search value (multi-actor fields come back as arrays). */
function sv($row, $f) { $v = $row[(string)F[$f]] ?? ($row[F[$f]] ?? null);
    if (is_array($v)) { $v = implode(', ', array_filter(array_map('strval', $v))); }
    return $v === '' ? null : $v; }

$now = time();
$seen = []; $tickets = [];
foreach (array_merge($openRows, $oldRows) as $r) {
    $id = (int)sv($r, 'id'); if (!$id || isset($seen[$id])) continue; $seen[$id] = 1;
    $s      = (int)sv($r, 'status');
    $opened = sv($r, 'date');   $tOpen  = $opened ? strtotime($opened) : null;
    $solved = sv($r, 'solve') ?: sv($r, 'close');
    $tSolve = $solved ? strtotime($solved) : null;
    $open   = $s < 5;

    // SLA state helper: green on time / amber <25% margin left / red breached
    $sla = function (?string $deadline, ?int $doneAt) use ($now, $tOpen, $open) {
        if (!$deadline) return null;
        $dl = strtotime($deadline); if (!$dl) return null;
        $left = $dl - ($doneAt ?? $now);
        $st = 'ok';
        if ($doneAt !== null)      { $st = ($doneAt <= $dl) ? 'ok' : 'late'; }
        elseif (!$open)            { $st = 'ok'; } // closed without timestamps: assume met
        elseif ($now > $dl)        { $st = 'late'; }
        else {
            $total = $tOpen ? max(1, $dl - $tOpen) : null;
            $st = ($total && ($dl - $now) < 0.25 * $total) ? 'warn' : 'run';
        }
        return ['dl' => date('Y-m-d H:i', $dl), 'left' => $dl - ($doneAt ?? $now), 'st' => $st];
    };

    // TTO: taken-into-account delay (seconds since opening) when GLPI provides it
    $tookRaw = sv($r, 'took');
    $takenAt = null;
    if ($tookRaw !== null && is_numeric($tookRaw) && (int)$tookRaw > 0 && $tOpen) { $takenAt = $tOpen + (int)$tookRaw; }
    elseif ($s > 1 && $tookRaw === null) { $takenAt = null; } // unknown; evaluated as open-run/na below
    $tto = $sla(sv($r, 'tto'), ($s > 1 && $takenAt === null && sv($r, 'tto')) ? null : $takenAt);
    if ($tto && $s > 1 && $takenAt === null) { $tto['st'] = 'na'; } // taken, but GLPI gave no timestamp: don't guess

    $tickets[] = [
        'id' => $id, 't' => (string)sv($r, 'title'), 's' => $s,
        'prio' => (int)sv($r, 'prio'), 'ty' => (int)(sv($r, 'type') ?? 0),
        'req' => sv($r, 'req'), 'tech' => sv($r, 'tech'), 'grp' => sv($r, 'grp'),
        'cat' => sv($r, 'cat'), 'ent' => sv($r, 'ent'),
        'date' => $opened, 'solved' => $solved,
        'took' => ($tookRaw !== null && is_numeric($tookRaw)) ? (int)$tookRaw : null, // s hasta la toma
        'rsec' => ($tOpen && $tSolve) ? max(0, $tSolve - $tOpen) : null,              // s hasta resolver
        'ttr' => $sla(sv($r, 'ttr'), $tSolve),
        'tto' => $tto,
    ];
}

// ---- resolver IDs → nombres (la búsqueda devuelve ids numéricos para usuarios/grupos) ----
function collectIds(array $tickets, string $k): array
{
    $ids = [];
    foreach ($tickets as $t) {
        foreach (explode(',', (string)($t[$k] ?? '')) as $v) {
            $v = trim($v);
            if ($v !== '' && ctype_digit($v)) { $ids[(int)$v] = 1; }
        }
    }
    return array_keys($ids);
}
function nameMap(string $type, array $ids, string $tok): array
{
    if (!$ids) return [];
    $map = []; $start = 0; $page = 250; $bulkOk = false;
    do {
        [$c, $b] = glpi_fetch('/' . $type, ['range' => $start . '-' . ($start + $page - 1)], $tok);
        if ($c >= 400 || !is_array($b)) break;
        $bulkOk = true;
        $rows = (isset($b[0]) || $b === []) ? $b : [$b];
        foreach ($rows as $u) {
            if (!is_array($u) || !isset($u['id'])) continue;
            $n = $type === 'User' ? trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? '')) : '';
            $map[(int)$u['id']] = $n !== '' ? $n : (string)($u['completename'] ?? $u['name'] ?? $u['id']);
        }
        $got = count($rows); $start += $got;
    } while ($got === $page && $start < 2000);
    if (!$bulkOk) { // el perfil no puede listar: resolver solo los ids necesarios, uno a uno
        foreach (array_slice($ids, 0, 100) as $id) {
            [$c, $u] = glpi_fetch('/' . $type . '/' . $id, [], $tok);
            if ($c === 200 && is_array($u)) {
                $n = $type === 'User' ? trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? '')) : '';
                $map[$id] = $n !== '' ? $n : (string)($u['completename'] ?? $u['name'] ?? $id);
            }
        }
    }
    return $map;
}
$umap = nameMap('User', array_unique(array_merge(collectIds($tickets, 'req'), collectIds($tickets, 'tech'))), $tok);
$gmap = nameMap('Group', collectIds($tickets, 'grp'), $tok);
$fix = function ($v, $map) {
    if ($v === null) return null;
    $out = [];
    foreach (explode(',', (string)$v) as $p) {
        $p = trim($p); if ($p === '') continue;
        $out[] = (ctype_digit($p) && isset($map[(int)$p])) ? $map[(int)$p] : $p;
    }
    return $out ? implode(', ', $out) : null;
};
foreach ($tickets as &$t) {
    $t['req']  = $fix($t['req'],  $umap);
    $t['tech'] = $fix($t['tech'], $umap);
    $t['grp']  = $fix($t['grp'],  $gmap);
}
unset($t);

// Open first (nearest TTR deadline first), then recent solved
usort($tickets, function ($a, $b) {
    $ao = $a['s'] < 5; $bo = $b['s'] < 5;
    if ($ao !== $bo) return $ao ? -1 : 1;
    if ($ao) {
        $ad = $a['ttr']['dl'] ?? '9999'; $bd = $b['ttr']['dl'] ?? '9999';
        return strcmp($ad, $bd) ?: ($b['prio'] <=> $a['prio']);
    }
    return strcmp($b['solved'] ?? '', $a['solved'] ?? '');
});

$openT   = array_filter($tickets, fn($t) => $t['s'] < 5);
$oldT    = array_filter($tickets, fn($t) => $t['s'] >= 5);
$breached = array_filter($openT, fn($t) => (($t['ttr']['st'] ?? '') === 'late') || (($t['tto']['st'] ?? '') === 'late'));
$withTtr = array_filter($oldT, fn($t) => $t['ttr'] !== null);
$metTtr  = array_filter($withTtr, fn($t) => $t['ttr']['st'] === 'ok');

echo json_encode([
    'days'     => $days, 'from' => $from, 'to' => $to,
    'glpi_url' => rtrim((string)(cfg()['glpi']['url'] ?? ''), '/'),
    'user'     => $_SESSION['user'] ?? '',
    'tickets'  => array_values($tickets),
    'kpis'     => [
        'open'    => count($openT),
        'new'     => count(array_filter($openT, fn($t) => $t['s'] === 1)),
        'working' => count(array_filter($openT, fn($t) => $t['s'] === 2 || $t['s'] === 3)),
        'waiting' => count(array_filter($openT, fn($t) => $t['s'] === 4)),
        'solved'  => count($oldT),
        'breach'  => count($breached),
        'slapct'  => count($withTtr) ? (int)round(100 * count($metTtr) / count($withTtr)) : null,
    ],
], JSON_UNESCAPED_UNICODE);
