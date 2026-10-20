<?php
/**
 * data-normas.php — Per-standard compliance view (Bloque B del proyecto #57).
 *
 * Cruza el catálogo de normas (config/frameworks.json) con los controles
 * propios (config/controls.json) y el estado real de cada proceso (evidencia
 * en tickets de GLPI, vía cmp-lib.php). Calcula, por entidad y por norma:
 *   - % de cumplimiento (parcial cuenta medio; los "na" no cuentan)
 *   - estado de cada requisito: cubierto / parcial / brecha / sincontrol
 *   - controles pendientes ordenados por cuántos requisitos cubrirían
 *   - matriz de solapamiento entre normas ("si cumplís A, cubrís X% de B")
 *
 * El mapeo control->requisito es criterio interno (a validar con el auditor):
 * GLPI sigue siendo la única fuente de verdad de lo que ocurrió; acá solo se
 * guarda qué se espera y cómo se relaciona con las normas.
 * @license MIT
 */
require dirname(__DIR__) . '/cmp-lib.php'; // también carga lib.php
panel_session();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['glpi_token'])) { http_response_code(401); echo json_encode(['auth' => false]); exit; }
$tok = $_SESSION['glpi_token'];

$cfgDir = dirname(__DIR__) . '/config';
$readJson = function (string $f): array {
    return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
};
$fw       = ($readJson("$cfgDir/frameworks.json")['frameworks'] ?? []);
// Mapeo real; si no existe, el ejemplo demo (instalación nueva).
$ctrlCfg  = $readJson("$cfgDir/controls.json");
if (!$ctrlCfg) { $ctrlCfg = $readJson("$cfgDir/controls.example.json"); }
$controls = array_values($ctrlCfg['controls'] ?? []);
$scope    = $ctrlCfg['scope'] ?? [];
$procDefs = [];
foreach (($readJson("$cfgDir/processes.json")['processes'] ?? []) as $p) {
    if (isset($p['id'])) { $procDefs[(string)$p['id']] = $p; }
}

// --- entidad a evaluar ---------------------------------------------------
$scopeList = [];
foreach ($scope as $eid => $s) { $scopeList[] = ['id' => (int)$eid, 'name' => (string)($s['name'] ?? ('#' . $eid))]; }
usort($scopeList, fn($a, $b) => strcasecmp($a['name'], $b['name']));
$entId = isset($_GET['ent']) && $_GET['ent'] !== '' ? (int)$_GET['ent'] : ($scopeList[0]['id'] ?? 0);
$entName = '';
foreach ($scopeList as $s) { if ($s['id'] === $entId) { $entName = $s['name']; } }

// normas que aplican a la entidad (o todas las del catálogo si no hay scope)
$fwKeys = $scope[(string)$entId]['frameworks'] ?? array_keys($fw);
$fwKeys = array_values(array_filter($fwKeys, fn($k) => isset($fw[$k])));

// --- estado de cada proceso referido por los controles (una sola vez) ----
$now = time(); $techIds = []; $procFull = [];
foreach ($controls as $c) {
    foreach ((array)($c['processes'] ?? []) as $pid) {
        $pid = (string)$pid;
        if (isset($procFull[$pid]) || !isset($procDefs[$pid])) { continue; }
        $procFull[$pid] = evalProc($procDefs[$pid], $entId ?: null, $entName, $now, $techIds, $tok);
    }
}
// resolver ids de técnico a nombres en la evidencia (para el drill-down)
$allRows = array_values($procFull);
cmpResolveTechs($allRows, $techIds, $tok);
$procFull = [];
foreach ($allRows as $r) { $procFull[$r['pid']] = $r; }
$procState = []; // atajo pid -> estado para el cálculo de cobertura
foreach ($procFull as $pid => $r) { $procState[$pid] = $r['state']; }

// --- cobertura de un control (recurring = el peor de sus procesos) -------
// valor de cobertura por estado: cuánto "cubre" un requisito ese control.
$COV = ['ok' => 1.0, 'ok_find' => 0.5, 'ok_crit' => 0.25, 'run' => 0.0, 'late' => 0.0, 'nodata' => 0.0];
$controlCov = []; $controlState = [];
foreach ($controls as $c) {
    $id = (string)($c['id'] ?? ''); if ($id === '') { continue; }
    $kind = $c['kind'] ?? 'recurring';
    $worst = null; $worstState = 'nodata';
    if ($kind === 'recurring') {
        foreach ((array)($c['processes'] ?? []) as $pid) {
            $st = $procState[(string)$pid] ?? 'nodata';
            $v  = $COV[$st] ?? 0.0;
            if ($worst === null || $v < $worst) { $worst = $v; $worstState = $st; }
        }
    } elseif ($kind === 'na') {
        $worst = null; $worstState = 'na'; // no aplica: no cuenta en el denominador
    } else { // static u otros: aún sin evidencia automatizada
        $worst = 0.0; $worstState = 'nodata';
    }
    $controlCov[$id]   = $worst === null ? ($kind === 'na' ? null : 0.0) : $worst;
    $controlState[$id] = $worstState;
}

// cadena de evidencia por control: sus procesos con estado y tickets (drill-down)
$cproc = [];
foreach ($controls as $c) {
    $id = (string)($c['id'] ?? ''); if ($id === '') { continue; }
    $ps = [];
    foreach ((array)($c['processes'] ?? []) as $pid) {
        $r = $procFull[(string)$pid] ?? null;
        if (!$r) { continue; }
        $ps[] = ['pid' => $r['pid'], 'name' => $r['name'], 'state' => $r['state'], 'every' => $r['every'],
                 'last' => $r['last'], 'days' => $r['days'], 'dueIn' => $r['dueIn'], 'ev' => $r['ev']];
    }
    $cproc[$id] = ['name' => cName($controls, $id), 'kind' => $c['kind'] ?? 'recurring', 'state' => $controlState[$id], 'processes' => $ps];
}

// índice requisito -> controles que lo mapean, por norma
$byReq = []; // [fwKey][ref] = [controlId,...]
$ctrlMapsFw = []; // [fwKey] = [controlId,...] (controles que tocan esa norma)
foreach ($controls as $c) {
    $id = (string)($c['id'] ?? '');
    foreach ((array)($c['maps'] ?? []) as $fwk => $refs) {
        foreach ((array)$refs as $ref) { $byReq[$fwk][(string)$ref][] = $id; }
        if ($refs) { $ctrlMapsFw[$fwk][] = $id; }
    }
}

// --- cálculo por norma ---------------------------------------------------
$fwOut = []; $reqCovByFw = []; // reqCovByFw[fwk][ref] = cov (para solapamiento/pending)
foreach ($fwKeys as $fwk) {
    $def = $fw[$fwk];
    $byDomain = []; $sumCov = 0.0; $applic = 0;
    $cCub = $cPar = $cBre = $cSin = 0;
    foreach (($def['requirements'] ?? []) as $rq) {
        $ref = (string)$rq['ref']; $dom = (string)($rq['domain'] ?? '—');
        $ctrlIds = $byReq[$fwk][$ref] ?? [];
        // na: si TODOS los controles que lo mapean son 'na', el requisito no cuenta
        $cov = 0.0; $hasApplicable = false; $clist = [];
        foreach ($ctrlIds as $cid) {
            $st = $controlState[$cid] ?? 'nodata';
            $clist[] = ['id' => $cid, 'name' => cName($controls, $cid), 'state' => $st];
            if ($controlCov[$cid] === null) { continue; }           // control 'na'
            $hasApplicable = true;
            $cov = max($cov, (float)$controlCov[$cid]);
        }
        if ($ctrlIds && !$hasApplicable) {
            $status = 'na';                                          // declarado no aplica
        } elseif (!$ctrlIds) {
            $status = 'sincontrol'; $cBre++; $cSin++; $applic++;     // brecha sin cubrir
        } elseif ($cov >= 1.0) {
            $status = 'cubierto'; $cCub++; $applic++; $sumCov += 1.0;
        } elseif ($cov > 0.0) {
            $status = 'parcial'; $cPar++; $applic++; $sumCov += $cov;
        } else {
            $status = 'brecha'; $cBre++; $applic++;                  // mapeado pero vencido/sin evidencia
        }
        if ($status !== 'na') { $reqCovByFw[$fwk][$ref] = ($status === 'cubierto' ? 1.0 : ($status === 'parcial' ? $cov : 0.0)); }
        $byDomain[$dom][] = ['ref' => $ref, 'title' => (string)($rq['title'] ?? ''), 'status' => $status, 'cov' => round($cov, 2), 'controls' => $clist];
    }
    $domains = [];
    foreach (($def['domains'] ?? array_keys($byDomain)) as $dom) {
        if (!isset($byDomain[$dom])) { continue; }
        $domains[] = ['name' => $dom, 'reqs' => $byDomain[$dom]];
    }
    $pct = $applic > 0 ? (int)round(100 * $sumCov / $applic) : 0;
    $fwOut[$fwk] = [
        'key' => $fwk, 'name' => (string)($def['name'] ?? $fwk), 'short' => (string)($def['short'] ?? $fwk),
        'note' => (string)($def['note'] ?? ''),
        'pct' => $pct,
        'counts' => ['cubierto' => $cCub, 'parcial' => $cPar, 'brecha' => $cBre, 'sincontrol' => $cSin, 'total' => $applic],
        'domains' => $domains,
    ];
}

// --- controles pendientes: cuántos requisitos (no cubiertos aún) ganarían -
$pending = [];
foreach ($controls as $c) {
    $id = (string)($c['id'] ?? ''); if ($id === '') { continue; }
    if (($controlCov[$id] ?? 0.0) >= 1.0 || $controlState[$id] === 'na') { continue; } // ya cubre / no aplica
    $byFw = []; $total = 0;
    foreach ((array)($c['maps'] ?? []) as $fwk => $refs) {
        if (!in_array($fwk, $fwKeys, true)) { continue; }
        foreach ((array)$refs as $ref) {
            if (($reqCovByFw[$fwk][(string)$ref] ?? 0.0) < 1.0) { $byFw[$fwk] = ($byFw[$fwk] ?? 0) + 1; $total++; }
        }
    }
    if ($total > 0) { $pending[] = ['id' => $id, 'name' => cName($controls, $id), 'state' => $controlState[$id], 'byFw' => $byFw, 'total' => $total]; }
}
usort($pending, fn($a, $b) => $b['total'] <=> $a['total']);

// --- matriz de solapamiento: "si cumplís A, cubrís X% de B" ---------------
$overlap = [];
foreach ($fwKeys as $a) {
    foreach ($fwKeys as $b) {
        if ($a === $b) { continue; }
        $ctrlsA = array_flip($ctrlMapsFw[$a] ?? []);
        $refsB = array_keys($reqCovByFw[$b] ?? []); // requisitos aplicables de B
        if (!$refsB) { $overlap[$a][$b] = 0; continue; }
        $hit = 0;
        foreach ($refsB as $ref) {
            foreach ($byReq[$b][$ref] ?? [] as $cid) { if (isset($ctrlsA[$cid])) { $hit++; break; } }
        }
        $overlap[$a][$b] = (int)round(100 * $hit / count($refsB));
    }
}

echo json_encode([
    'glpi_url' => rtrim((string)(cfg()['glpi']['url'] ?? ''), '/'),
    'canEdit'  => !empty($_SESSION['isAdmin']),
    'entId'    => $entId, 'entName' => $entName,
    'scope'    => $scopeList,
    'fwKeys'   => $fwKeys,
    'frameworks' => $fwOut,
    'cproc'    => $cproc,
    'pending'  => $pending,
    'overlap'  => $overlap,
], JSON_UNESCAPED_UNICODE);

/** Nombre legible de un control por su id. */
function cName(array $controls, string $id): string
{
    foreach ($controls as $c) { if ((string)($c['id'] ?? '') === $id) { return (string)($c['name'] ?? $id); } }
    return $id;
}
