<?php
/**
 * cmp-lib.php — Compliance evaluation shared by data-cumplimiento.php (panel)
 * and data-normas.php (per-standard view). One source of truth for how a
 * process's state is derived from GLPI tickets, so both endpoints agree.
 *
 * The panel does not verify the backups — it verifies that somebody verified
 * the backups, and now also WHAT the result was (ticket Solution type, field
 * 23): ok / ok_find (al día con hallazgos) / ok_crit (al día, crítico).
 * @license MIT
 */

require_once __DIR__ . '/lib.php';

// Search-option ids of GLPI /search/Ticket used by the evaluator.
const CF      = ['id' => 2, 'title' => 1, 'status' => 12, 'date' => 15, 'close' => 16, 'solve' => 17, 'cat' => 7, 'tech' => 5, 'ent' => 80, 'stype' => 23];
const PERIODS = ['daily' => 1, 'weekly' => 7, 'monthly' => 30, 'quarterly' => 91, 'halfyearly' => 182, 'yearly' => 365];
const CYCLES  = 12; // segments in the history bar

/** Paginated /search/Ticket limited to what the history needs. On 401 it emits
 *  {"auth":false} and exits — both callers want exactly that. */
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

// El panel no solo mira si se ejecutó: mira el RESULTADO. Lo lee del Tipo de
// solución del ticket (campo 23, que devuelve el nombre). Convención RootHard:
// "Sin hallazgos" = al día, "Con hallazgos" = ámbar, "Con hallazgos críticos" = rojo.
function cmpFinding(?string $stype): string
{
    $s = $stype === null ? '' : mb_strtolower(trim($stype));
    if ($s === '') { return 'ok'; }                       // sin resultado = al día (como antes)
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
    if (strpos($s, 'critic') !== false)                    { return 'ok_crit'; }
    if (strpos($s, 'hallazgo') !== false && strpos($s, 'sin') === false) { return 'ok_find'; }
    return 'ok';
}
// Orden de severidad, para quedarse con el peor resultado de un ciclo.
function cmpRank(string $lvl): int { return ['ok' => 0, 'find' => 1, 'crit' => 2][$lvl] ?? 0; }

// Evalúa un proceso para UN alcance de entidad (o global si $entId === null).
// Devuelve una fila del panel + acumula los ids de técnico a resolver.
function evalProc(array $p, ?int $entId, string $entName, int $now, array &$techIds, string $tok): array
{
    $P     = (PERIODS[$p['every']] ?? 30) * 86400;
    $grace = (int)($p['grace_days'] ?? 0) * 86400;
    $base = [($p['match']['by'] ?? 'category') === 'title'
        ? ['field' => CF['title'], 'searchtype' => 'contains', 'value' => (string)$p['match']['value']]
        : ['field' => CF['cat'], 'searchtype' => 'equals', 'value' => (int)$p['match']['value']]];
    if ($entId !== null && $entId > 0) {
        $base[] = ['link' => 'AND', 'field' => CF['ent'], 'searchtype' => 'equals', 'value' => $entId];
    }

    $since = date('Y-m-d H:i:s', $now - CYCLES * $P);
    $old = cmpSearch(array_merge($base, [
        ['link' => 'AND', 'field' => CF['status'], 'searchtype' => 'equals', 'value' => 'old'],
        ['link' => 'AND', 'field' => CF['date'], 'searchtype' => 'morethan', 'value' => $since],
    ]), $tok);
    $open = cmpSearch(array_merge($base, [
        ['link' => 'AND', 'field' => CF['status'], 'searchtype' => 'equals', 'value' => 'notold'],
    ]), $tok);

    $done = []; // cada evidencia con su fecha y su tipo de solución
    foreach ($old as $r) {
        $d = cv($r, 'solve') ?: cv($r, 'close') ?: cv($r, 'date');
        $ts = $d ? strtotime($d) : null;
        if ($ts) { $done[] = ['ts' => $ts, 'stype' => cv($r, 'stype')]; }
    }
    usort($done, fn($a, $b) => $b['ts'] <=> $a['ts']);
    $lastDone  = $done[0]['ts'] ?? null;
    $lastStype = $done[0]['stype'] ?? null;

    $due = $lastDone ? $lastDone + $P + $grace : null;
    if ($lastDone === null && !$open) { $state = 'nodata'; }
    elseif ($lastDone === null)       { $state = 'run'; }
    elseif ($now > $due)              { $state = 'late'; }
    elseif ($open)                    { $state = 'run'; }
    else                              { $state = cmpFinding($lastStype); } // ok | ok_find | ok_crit

    $cycles = [];
    $lvlOf  = ['ok' => 'ok', 'ok_find' => 'find', 'ok_crit' => 'crit'];
    for ($i = CYCLES - 1; $i >= 0; $i--) {
        $from = $now - ($i + 1) * $P; $to = $now - $i * $P;
        $hit = null; // peor resultado del ciclo (null = ciclo sin evidencia)
        foreach ($done as $x) {
            if ($x['ts'] >= $from && $x['ts'] < $to) {
                $lvl = $lvlOf[cmpFinding($x['stype'])];
                if ($hit === null || cmpRank($lvl) > cmpRank($hit)) { $hit = $lvl; }
            }
        }
        $cycles[] = $hit ?? 0; // 0 = sin evidencia · 'ok'|'find'|'crit' = cumplido con ese resultado
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
            'stype' => cv($r, 'stype'), // resultado (Tipo de solución)
        ];
    }

    return [
        'id'    => $p['id'] . ($entId ? '@' . $entId : ''),
        'pid'   => $p['id'], // id del proceso sin sufijo de entidad (para mapear controles)
        'name'  => $p['name'], 'every' => $p['every'], 'grace' => (int)($p['grace_days'] ?? 0),
        'ent'   => $entName, 'entId' => $entId,
        'owner' => (string)($p['owner_name'] ?? ''), 'grp' => (string)($p['group_name'] ?? ''),
        'state' => $state,
        'last'  => $lastDone ? date('Y-m-d H:i', $lastDone) : null,
        'days'  => $lastDone ? (int)floor(($now - $lastDone) / 86400) : null,
        'dueIn' => $due ? (int)ceil(($due - $now) / 86400) : null,
        'cycles' => $cycles,
        'ev'     => $ev,
    ];
}

/** Resuelve ids numéricos de técnico a nombres (la búsqueda los devuelve como
 *  ids de actor). Modifica $result en sitio, igual que antes. */
function cmpResolveTechs(array &$result, array $techIds, string $tok): void
{
    if (!$techIds) { return; }
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
        if (empty($pr['ev'])) continue;
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
