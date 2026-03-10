<?php
class Graph {
    private static ?array $mem = null;

    static function load(): array {
        if (self::$mem !== null) return self::$mem;
        if (!file_exists(GRAPH_FILE)) return self::$mem = self::blank();
        $g = json_decode(file_get_contents(GRAPH_FILE), true);
        return self::$mem = (is_array($g) && isset($g['nodes'])) ? $g : self::blank();
    }

    static function save(array $g): void {
        self::$mem = null;
        @mkdir(dirname(GRAPH_FILE), 0755, true);
        $g['meta']['updated']    = date('c');
        $g['meta']['node_count'] = count($g['nodes']);
        $g['meta']['edge_count'] = count($g['edges']);
        // Prune if oversized
        if (count($g['nodes']) > GRAPH_MAX) $g = self::prune($g);
        file_put_contents(GRAPH_FILE, json_encode($g, JSON_UNESCAPED_UNICODE));
    }

    static function addNode(array &$g, array $node): bool {
        $id = $node['id'] ?? self::nid($node['title'] ?? uniqid());
        $node['id'] = $id;
        if (isset($g['nodes'][$id])) {
            $g['nodes'][$id]['mentions'] = ($g['nodes'][$id]['mentions'] ?? 1) + 1;
            return false;
        }
        $g['nodes'][$id] = $node + ['mentions' => 1, 'added' => date('c')];
        return true;
    }

    static function addEdge(array &$g, string $from, string $to, string $type, float $w = 0.5): void {
        foreach ($g['edges'] as $e)
            if ($e['from'] === $from && $e['to'] === $to && $e['type'] === $type) return;
        $g['edges'][] = ['from' => $from, 'to' => $to, 'type' => $type, 'w' => $w, 'ts' => date('c')];
    }

    static function link(array &$g, string $nid): void {
        $n = $g['nodes'][$nid] ?? null;
        if (!$n) return;
        $nw  = self::words($n['title'] ?? '');
        $nd  = $n['domain'] ?? '';
        $nts = substr($n['timestamp'] ?? '', 0, 10);
        $nc  = $n['coords'] ?? null;
        $linked = 0;
        foreach ($g['nodes'] as $oid => $x) {
            if ($oid === $nid || $linked >= 30) break;
            if (count(array_intersect($nw, self::words($x['title'] ?? ''))) >= 2) {
                self::addEdge($g, $nid, $oid, 'mentions', 0.6); $linked++; continue;
            }
            if ($x['domain'] === $nd && substr($x['timestamp'] ?? '', 0, 10) === $nts) {
                self::addEdge($g, $nid, $oid, 'concurrent_with', 0.35); $linked++;
            }
        }
    }

    //Queries
    static function byDomain(array $g, string $d): array {
        $rows = array_values(array_filter($g['nodes'], fn($n) => ($n['domain'] ?? '') === $d));
        usort($rows, fn($a, $b) => self::so($a) <=> self::so($b) ?: strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''));
        return $rows;
    }

    static function recent(array $g, int $n = 80): array {
        $rows = array_values($g['nodes']);
        usort($rows, fn($a, $b) => strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''));
        return array_slice($rows, 0, $n);
    }

    static function withCoords(array $g): array {
        return array_values(array_filter($g['nodes'], fn($n) => !empty($n['coords']) && is_array($n['coords'])));
    }

    static function context(array $g, int $n = 70): string {
        $lines = [];
        foreach (self::recent($g, $n) as $x)
            $lines[] = sprintf('[%s|%s|%s] %s', strtoupper($x['domain'] ?? ''), strtoupper($x['severity'] ?? ''), substr($x['timestamp'] ?? '', 0, 10), $x['title'] ?? '');
        return count($g['nodes']) . "N/" . count($g['edges']) . "E\n" . implode("\n", $lines);
    }

    static function focusedCtx(array $g, string $q, int $n = 50): string {
        $qw = array_filter(explode(' ', preg_replace('/[^a-z0-9 ]/', '', strtolower($q))), fn($w) => strlen($w) > 3);
        $sc = [];
        foreach ($g['nodes'] as $x) {
            $t = strtolower(($x['title'] ?? '') . ($x['location'] ?? '') . ($x['domain'] ?? ''));
            $s = 0;
            foreach ($qw as $w) if (str_contains($t, $w)) $s++;
            if ($s > 0) $sc[] = [$s, $x];
        }
        if (!$sc) return self::context($g, $n);
        usort($sc, fn($a, $b) => $b[0] <=> $a[0] ?: self::so($a[1]) <=> self::so($b[1]));
        $lines = [];
        foreach (array_slice($sc, 0, $n) as [, $x])
            $lines[] = sprintf('[%s|%s|%s] %s', strtoupper($x['domain'] ?? ''), strtoupper($x['severity'] ?? ''), substr($x['timestamp'] ?? '', 0, 10), $x['title'] ?? '');
        return count($sc) . " relevant:\n" . implode("\n", $lines);
    }

    static function stats(): array {
        $g  = self::load();
        $bd = [];
        foreach (array_keys(DOMAINS) as $d) $bd[$d] = count(self::byDomain($g, $d));
        return [
            'nodes'      => count($g['nodes']),
            'edges'      => count($g['edges']),
            'geo'        => count(self::withCoords($g)),
            'updated'    => $g['meta']['updated'] ?? null,
            'cache'      => count(glob(CACHE_DIR . '/*.hc') ?: []),
            'by_domain'  => $bd,
        ];
    }

    static function nid(string $s): string {
        return substr(md5(strtolower(trim($s))), 0, 12);
    }

    private static function blank(): array {
        return ['nodes' => [], 'edges' => [], 'meta' => ['version' => GOE_VER, 'created' => date('c'), 'updated' => null]];
    }

    private static function so(array $n): int {
        return ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3][$n['severity'] ?? 'low'] ?? 3;
    }

    private static function words(string $s): array {
        return array_filter(explode(' ', preg_replace('/[^a-z0-9 ]/', '', strtolower($s))), fn($w) => strlen($w) > 3);
    }

    private static function prune(array $g): array {
        $keep = GRAPH_MAX - 500;
        $rows = array_values($g['nodes']);
        usort($rows, fn($a, $b) => strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''));
        $rows = array_slice($rows, 0, $keep);
        $ids  = array_flip(array_column($rows, 'id'));
        $g['nodes'] = [];
        foreach ($rows as $n) $g['nodes'][$n['id']] = $n;
        $g['edges'] = array_values(array_filter($g['edges'], fn($e) => isset($ids[$e['from']]) && isset($ids[$e['to']])));
        return $g;
    }
}
