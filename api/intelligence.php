<?php
class Intel {

    static function brief(array $g): array {
        if (empty($g['nodes']) || count($g['nodes']) < 5) {
            return ['brief' => "No data yet. Click ⟳ SYNC to pull live sources.", 'model' => MODEL_REASON, 'tokens' => 0];
        }
        $ctx = Graph::context($g, 60);
        $sys = "You are a senior global intelligence analyst at GOE (Global Ontology Engine).\n"
             . "Synthesise a concise World Intelligence Brief from the knowledge graph data.\n"
             . "Format exactly:\n"
             . "🔴 CRITICAL SIGNALS\n• [3 bullet points with country, event, significance]\n\n"
             . "⚠️ EMERGING RISKS\n• [2-3 developing situations]\n\n"
             . "🔗 CROSS-DOMAIN CONVERGENCES\n• [1-2 links between different domains]\n\n"
             . "🇮🇳 INDIA RELEVANCE\n• [2-3 items directly relevant to India's interests]\n\n"
             . "Be specific — cite real countries, actors, events from the data. Max 380 words.";
        $out = Groq::reason("Graph snapshot:\n\n{$ctx}\n\nGenerate World Brief:", $sys, false);
        return [
            'brief'  => $out ?? 'Groq unavailable — set GROQ_API_KEY environment variable.',
            'model'  => MODEL_REASON,
            'tokens' => strlen($out ?? ''),
        ];
    }

    static function query(string $q, array $g): array {
        $ctx = Graph::focusedCtx($g, $q, 50);
        $sys = "You are a global intelligence analyst. Answer using ONLY the GOE knowledge graph data provided.\n"
             . "Be precise. Cite specific events. Do NOT hallucinate anything not in the data.\n"
             . "If the data is insufficient, say so clearly. Max 280 words.";
        $ans = Groq::reason("Data:\n{$ctx}\n\nQuestion: {$q}\n\nAnswer:", $sys, true);
        return [
            'answer'  => $ans ?? 'Groq unavailable — set GROQ_API_KEY.',
            'question'=> $q,
            'tokens'  => strlen($ans ?? ''),
            'scanned' => count($g['nodes']),
        ];
    }

    // CII = baseline×0.40 + conflict×0.25 + velocity×0.20 + economic×0.15
    static function cii(array $g): array {
        $nodes  = array_values($g['nodes']);
        $cutoff = time() - 7 * 86400;
        $scores = [];
        foreach (CII_BASELINE as $country => $bl) {
            $conf = $crit = $news = 0;
            $econ = [];
            foreach ($nodes as $n) {
                $text = strtolower(($n['title'] ?? '') . ($n['location'] ?? ''));
                if (!str_contains($text, strtolower($country))) continue;
                if (strtotime($n['timestamp'] ?? 'now') < $cutoff) continue;
                $news++;
                if (in_array($n['domain'] ?? '', ['defense', 'geopolitics'])) $conf++;
                if (($n['severity'] ?? '') === 'critical') $crit++;
                if ($n['type'] === 'indicator') $econ[] = (float)($n['value'] ?? 0);
            }
            $cs  = min(100, $conf * 8 + $crit * 20);
            $vs  = min(100, $news * 6);
            $es  = $econ ? min(100, (array_sum($econ) / count($econ)) * 3) : 0;
            $cii = round($bl * 0.40 + $cs * 0.25 + $vs * 0.20 + $es * 0.15, 1);
            $scores[] = [
                'country' => $country,
                'score'   => min(100, (int)$cii),
                'baseline'=> $bl,
                'trend'   => round($cii - $bl, 1),
                'signals' => array_values(array_filter([
                    $conf ? "{$conf} conflict" : null,
                    $crit ? "{$crit} critical" : null,
                    $news ? "{$news} news"     : null,
                    $es > 20 ? 'econ↑'         : null,
                ])),
            ];
        }
        usort($scores, fn($a, $b) => $b['score'] <=> $a['score']);
        return $scores;
    }

    // Run ingest directly (fast within request)
    static function ingestNow(): array {
        set_time_limit(120);
        return Sources::ingestAll();
    }
}
