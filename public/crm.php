<?php
/**
 * crm.php — vista comercial de las entidades hijas de una "empresa" (entidad
 * padre). Generaliza el CRM: sirve para CUALQUIER entidad, no solo 2050 DEST.
 *
 *  - "Empresas/padres" seleccionables = config crm.parents (si está vacía,
 *    se autodescubren las entidades con hijas que el usuario puede ver).
 *  - "Clientes" = entidades hijas de la empresa seleccionada (?entidad=<id>).
 *  - Enriquecido con el plugin GLPI manageentities: contratos, fecha de
 *    renovación más próxima y responsable comercial.
 *  - Todo con la sesión del usuario → GLPI aplica el aislamiento por entidad.
 *
 * Solo admin/supervisor (eligen la empresa con la que trabajar).
 * @license MIT
 */
require dirname(__DIR__) . '/lib.php';
panel_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['glpi_token'])) { http_response_code(401); echo json_encode(['auth' => false]); exit; }
if (empty($_SESSION['isAdmin']) && empty($_SESSION['isSuper'])) {
    http_response_code(403); echo json_encode(['auth' => true, 'error' => 'forbidden']); exit;
}
$tok = $_SESSION['glpi_token'];

// ── Todas las entidades que el usuario puede ver ─────────────────────────────
list($c, $ents) = glpi_fetch('/Entity', ['range' => '0-1000'], $tok);
if ($c === 401 || $c === 403) { $_SESSION = []; http_response_code(401); echo json_encode(['auth' => false]); exit; }

$name = [];          // id → nombre
$hasKids = [];       // id → true si alguna entidad la tiene como padre
$childrenOf = [];    // padreId → [entidades hijas]
foreach ((array)$ents as $e) {
    $id = (int)($e['id'] ?? -1);
    if ($id < 0) { continue; }
    $name[$id] = ($e['completename'] ?? '') !== '' ? $e['completename'] : ($e['name'] ?? ('#' . $id));
    $pid = (int)($e['entities_id'] ?? -1);
    if ($pid >= 0) { $hasKids[$pid] = true; $childrenOf[$pid][] = $e; }
}

// ── Empresas/padres candidatas ───────────────────────────────────────────────
$cfgParents = array_map('intval', (array)(cfg()['crm']['parents'] ?? []));
if ($cfgParents) {
    $parents = array_values(array_filter($cfgParents, fn($id) => isset($name[$id])));  // solo las que ve
} else {
    $parents = array_values(array_filter(array_keys($name), fn($id) => !empty($hasKids[$id])));
}
usort($parents, fn($a, $b) => strcasecmp($name[$a] ?? '', $name[$b] ?? ''));
$parentList = array_map(fn($id) => ['id' => $id, 'name' => $name[$id] ?? ('#' . $id)], $parents);

// ── Empresa seleccionada ─────────────────────────────────────────────────────
$sel = isset($_GET['entidad']) ? (int)$_GET['entidad'] : (int)($parents[0] ?? -1);
if (!in_array($sel, $parents, true)) { $sel = (int)($parents[0] ?? -1); }

// ── Clientes = hijas de la empresa seleccionada ──────────────────────────────
$clientes = [];
foreach ($childrenOf[$sel] ?? [] as $e) {
    $clientes[(int)$e['id']] = [
        'id'          => (int)$e['id'],
        'nombre'      => $e['name'] ?? '',
        'comentario'  => $e['comment'] ?? '',
        'contratos'   => 0,
        'renovacion'  => null,
        'responsable' => null,
    ];
}

// ── Enriquecer con el plugin manageentities (si está) ────────────────────────
if ($clientes) {
    list($cc, $contratos) = glpi_fetch('/PluginManageentitiesContract', ['range' => '0-2000'], $tok);
    if ($cc < 400) {
        foreach ((array)$contratos as $ct) {
            $eid = (int)($ct['entities_id'] ?? 0);
            if (!isset($clientes[$eid])) { continue; }
            $clientes[$eid]['contratos']++;
            $r = $ct['date_renewal'] ?? null;
            if ($r && $r !== 'NULL' && (!$clientes[$eid]['renovacion'] || $r < $clientes[$eid]['renovacion'])) {
                $clientes[$eid]['renovacion'] = $r;
            }
        }
    }
    list($cb, $resp) = glpi_fetch('/PluginManageentitiesBusinesscontact',
        ['range' => '0-2000', 'expand_dropdowns' => 'true'], $tok);
    if ($cb < 400) {
        foreach ((array)$resp as $b) {
            $eid = (int)($b['entities_id'] ?? 0);
            if (isset($clientes[$eid]) && !empty($b['users_id'])) { $clientes[$eid]['responsable'] = $b['users_id']; }
        }
    }
}

// ── Días para renovar ────────────────────────────────────────────────────────
$hoy = new DateTimeImmutable('today');
foreach ($clientes as &$cl) {
    $cl['dias_para_renovar'] = null;
    if ($cl['renovacion']) {
        $f = date_create_immutable(substr($cl['renovacion'], 0, 10));
        if ($f) { $cl['dias_para_renovar'] = (int)$hoy->diff($f)->format('%r%a'); }
    }
}
unset($cl);
usort($clientes, fn($a, $b) => strcasecmp($a['nombre'], $b['nombre']));

$d = static fn($p) => count(array_filter($clientes, $p));
echo json_encode([
    'auth'     => true,
    'parents'  => $parentList,
    'selected' => $sel,
    'sel_name' => $name[$sel] ?? '',
    'clientes' => array_values($clientes),
    'avisos'   => [
        'vencidos'  => $d(fn($c) => $c['dias_para_renovar'] !== null && $c['dias_para_renovar'] < 0),
        'vencen_30' => $d(fn($c) => $c['dias_para_renovar'] !== null && $c['dias_para_renovar'] >= 0 && $c['dias_para_renovar'] <= 30),
        'vencen_60' => $d(fn($c) => $c['dias_para_renovar'] !== null && $c['dias_para_renovar'] > 30 && $c['dias_para_renovar'] <= 60),
    ],
], JSON_UNESCAPED_UNICODE);
