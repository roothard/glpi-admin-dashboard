<?php
/**
 * Generator — turns GLPI Projects into the dashboard's data.json (schema v2).
 *
 * Reads everything through the GLPI REST API (GlpiClient), so it works whether
 * the dashboard runs on the same host as GLPI or anywhere else. Output schema
 * matches the front-end in /public.
 *
 * @license MIT
 */
class DashboardGenerator
{
    private GlpiClient $api;
    private array $cfg;

    public function __construct(GlpiClient $api, array $cfg)
    {
        $this->api = $api;
        $this->cfg = $cfg;
    }

    /** Build and return the full data array (also see run() to write it). */
    public function build(): array
    {
        $this->api->initSession();
        try {
            return $this->assemble();
        } finally {
            $this->api->killSession();
        }
    }

    /** Build and write the JSON file; returns [projectCount, kbCount]. */
    public function run(string $outFile): array
    {
        $data = $this->build();
        $dir = dirname($outFile);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        file_put_contents($outFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $kb = array_sum(array_map(fn($p) => count($p['kb']), $data['projects']));
        return [count($data['projects']), $kb];
    }

    // ---- internals -------------------------------------------------------

    private function assemble(): array
    {
        // Catalogs (id → name/…) ------------------------------------------
        $states = [];
        foreach ($this->api->getAll('ProjectState') as $s) {
            $states[(int)$s['id']] = [
                'id'    => (int)$s['id'],
                'name'  => $s['name'] ?? '',
                'color' => $s['color'] ?? '#888',
                'code'  => $this->stateCode($s['name'] ?? ''),
            ];
        }

        $ents = [];
        foreach ($this->api->getAll('Entity') as $e) {
            $ents[(int)$e['id']] = ($e['completename'] ?? '') !== '' ? $e['completename'] : ($e['name'] ?? '-');
        }

        $users = [];
        foreach ($this->api->getAll('User') as $u) {
            $full = trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? ''));
            $users[(int)$u['id']] = $full !== '' ? $full : ($u['name'] ?? '-');
        }

        // Optional project-type filter ------------------------------------
        $typeId = 0;
        $typeName = trim((string)($this->cfg['project_type'] ?? ''));
        $projTypes = [];
        foreach ($this->api->getAll('ProjectType') as $t) {
            $projTypes[(int)$t['id']] = $t['name'] ?? '';
            if ($typeName !== '' && mb_strtolower($t['name'] ?? '') === mb_strtolower($typeName)) {
                $typeId = (int)$t['id'];
            }
        }
        if ($typeName !== '' && $typeId === 0) {
            throw new RuntimeException("Project type \"$typeName\" not found in GLPI.");
        }

        // All projects, then filter client-side ---------------------------
        $all = $this->api->getAll('Project');
        $byId = [];
        foreach ($all as $p) { $byId[(int)$p['id']] = $p; }

        $groupBy  = strtolower((string)($this->cfg['group_by'] ?? 'parent'));
        $onlyLeaf = ($groupBy === 'parent') || (bool)($this->cfg['include_only_leaf'] ?? false);
        $stripPre = (string)($this->cfg['area_strip_prefix'] ?? '');

        $leaves = array_filter($all, function ($p) use ($typeId, $onlyLeaf) {
            if (!empty($p['is_deleted']) || !empty($p['is_template'])) return false;
            if ($typeId && (int)($p['projecttypes_id'] ?? 0) !== $typeId) return false;
            if ($onlyLeaf && (int)($p['projects_id'] ?? 0) <= 0) return false;
            return true;
        });

        // Sort by priority desc, then name (mirrors the original).
        usort($leaves, function ($a, $b) {
            $pa = (int)($a['priority'] ?? 0); $pb = (int)($b['priority'] ?? 0);
            return $pb <=> $pa ?: strcasecmp($a['name'] ?? '', $b['name'] ?? '');
        });

        // Tasks in one bulk call, grouped by project. Note: GLPI restricts the
        // ProjectTask *listing* to tasks whose team includes the API user
        // (core behavior in Search::addDefaultWhere — not overridable via REST),
        // so only tasks visible to the token's user appear. Project-level
        // percent_done (below) is always accurate regardless.
        $tasksByProject = [];
        foreach ($this->api->getAll('ProjectTask') as $t) {
            $pp = (int)($t['projects_id'] ?? 0);
            if ($pp > 0) { $tasksByProject[$pp][] = $t; }
        }

        $kbCache = [];
        $projects = [];
        foreach ($leaves as $p) {
            $pid = (int)$p['id'];

            // Tasks (best-effort; see note above)
            $tasks = [];
            foreach ($tasksByProject[$pid] ?? [] as $t) {
                $st = $states[(int)($t['projectstates_id'] ?? 0)] ?? ['name'=>'','code'=>'curso','color'=>'#888'];
                $tasks[] = [
                    't'      => $t['name'] ?? '',
                    'estado' => $st['name'],
                    's'      => $st['code'],
                    'color'  => $st['color'],
                    'pct'    => (int)($t['percent_done'] ?? 0),
                    'start'  => $this->d10($t['plan_start_date'] ?? null),
                    'end'    => $this->d10($t['plan_end_date'] ?? null),
                ];
            }
            usort($tasks, fn($a, $b) => strcmp((string)$a['start'], (string)$b['start']));

            // Linked knowledge-base items
            $kb = [];
            foreach ($this->api->getSubItems('Project', $pid, 'KnowbaseItem_Item') as $link) {
                $kid = (int)($link['knowbaseitems_id'] ?? 0);
                if ($kid <= 0) continue;
                if (!array_key_exists($kid, $kbCache)) {
                    $item = $this->api->getItem('KnowbaseItem', $kid);
                    $kbCache[$kid] = $item ? [
                        'id'    => $kid,
                        'title' => $item['name'] ?? ('KB #' . $kid),
                        'html'  => $this->kbClean($item['answer'] ?? ''),
                    ] : null;
                }
                if ($kbCache[$kid]) { $kb[] = $kbCache[$kid]; }
            }

            $st   = $states[(int)($p['projectstates_id'] ?? 0)] ?? ['name'=>'','code'=>'curso','color'=>'#888'];
            $area = $this->areaOf($p, $byId, $ents, $projTypes, $groupBy, $stripPre);
            $done = array_values(array_filter($tasks, fn($t) => $t['pct'] >= 100));
            $pend = array_values(array_filter($tasks, fn($t) => $t['pct'] < 100));

            $projects[] = [
                'id'          => $pid,
                'n'           => $p['name'] ?? '',
                'area'        => $area,
                'entidad'     => $ents[(int)($p['entities_id'] ?? -1)] ?? '-',
                'responsable' => $users[(int)($p['users_id'] ?? -1)] ?? '-',
                'estado'      => $st['name'],
                's'           => $st['code'],
                'color'       => $st['color'],
                'pct'         => (int)($p['percent_done'] ?? 0),
                'd'           => $this->plain($p['content'] ?? ''),
                'start'       => $this->d10($p['plan_start_date'] ?? null),
                'end'         => $this->d10($p['plan_end_date'] ?? null),
                'last'        => $this->d10($p['date_mod'] ?? null),
                'tasks'       => $tasks,
                'done'        => array_map(fn($t) => ['t' => $t['t'], 'doc' => null], $done),
                'pend'        => array_map(fn($t) => $t['t'], $pend),
                'kb'          => $kb,
            ];
        }

        return [
            'generated' => date('d/m/Y H:i'),
            'states'    => array_values($states),
            'projects'  => $projects,
        ];
    }

    /** Derive the "zone/area" label for a project per the grouping strategy. */
    private function areaOf(array $p, array $byId, array $ents, array $types, string $groupBy, string $stripPre): string
    {
        switch ($groupBy) {
            case 'entity':
                $a = $ents[(int)($p['entities_id'] ?? -1)] ?? '';
                break;
            case 'type':
                $a = $types[(int)($p['projecttypes_id'] ?? -1)] ?? '';
                break;
            case 'parent':
            default:
                $parent = $byId[(int)($p['projects_id'] ?? 0)] ?? null;
                $a = $parent['name'] ?? '';
                break;
        }
        if ($stripPre !== '' && $a !== '') {
            $a = preg_replace('/^' . preg_quote($stripPre, '/') . '/u', '', $a);
        }
        return $a !== '' ? trim($a) : 'General';
    }

    /** Map a state name to a UI code using the configured keyword lists. */
    private function stateCode(string $name): string
    {
        $n = mb_strtolower($name);
        foreach (['prod' => 'done', 'plan' => 'planned', 'curso' => 'inprogress'] as $code => $key) {
            foreach ($this->cfg['state_map'][$key] ?? [] as $needle) {
                if ($needle !== '' && mb_strpos($n, mb_strtolower($needle)) !== false) {
                    return $code;
                }
            }
        }
        return 'curso'; // default: in progress
    }

    private function d10($s): ?string
    {
        return $s ? substr((string)$s, 0, 10) : null;
    }

    /** Strip HTML/entities to a single-line plain description. */
    private function plain(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($html))));
    }

    /** Sanitize KB HTML: drop dangerous tags, inline handlers and js: URLs. */
    private function kbClean(string $html): string
    {
        $h = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $h = preg_replace('#<(script|iframe|object|embed|style)[^>]*>.*?</\1>#is', ' ', $h);
        $h = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', ' ', $h);
        $h = preg_replace('#(href|src)\s*=\s*("javascript:[^"]*"|\'javascript:[^\']*\')#i', ' ', $h);
        return trim($h);
    }
}
