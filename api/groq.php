<?php
class Groq {
    private static int $idx   = 0;
    private static int $calls = 0;

    static function chat(
        string $prompt,
        string $sys    = '',
        string $model  = '',
        bool   $json   = false,
        int    $tokens = 800,
        float  $temp   = 0.1,
        bool   $cache  = true
    ): ?string {
        if (!GROQ_KEY || GROQ_KEY === 'YOUR_GROQ_API_KEY_HERE') return null;
        if (!$model) $model = MODEL_FAST;

        if ($cache) {
            $cf = Http::cacheFile($model . $prompt);
            $cv = Http::cacheGet($cf);
            if ($cv !== null) return $cv;
        }

        $msgs = [];
        if ($sys)    $msgs[] = ['role' => 'system',    'content' => $sys];
        $msgs[]              = ['role' => 'user',      'content' => $prompt];

        $body = [
            'model'       => $model,
            'messages'    => $msgs,
            'max_tokens'  => $tokens,
            'temperature' => $temp,
        ];
        if ($json) $body['response_format'] = ['type' => 'json_object'];

        $raw = Http::postJson(GROQ_URL, $body, [
            'Authorization: Bearer ' . GROQ_KEY,
        ]);

        if (!$raw) return null;
        $res  = json_decode($raw, true);
        $text = $res['choices'][0]['message']['content'] ?? null;

        if ($text && $cache) Http::cacheSet(Http::cacheFile($model . $prompt), $text);
        if ($text) self::$calls++;
        return $text;
    }

    /** Fast rotating model for bulk classification */
    static function rotate(string $prompt, string $sys = '', bool $json = false): ?string {
        $pool  = FAST_POOL;
        $model = $pool[self::$idx++ % count($pool)];
        return self::chat($prompt, $sys, $model, $json, 200, 0.0, true);
    }

    /** Heavy reasoning model */
    static function reason(string $prompt, string $sys = '', bool $cache = false): ?string {
        return self::chat($prompt, $sys, MODEL_REASON, false, 1200, 0.3, $cache);
    }

    /** Classify domain + severity */
    static function classifyEvent(string $title, string $desc = ''): array {
        // Step 1: keyword classifier
        $text = strtolower($title . ' ' . substr($desc, 0, 200));
        $domain   = 'geopolitics';
        $severity = 'low';

        foreach (DOM_KW as $d => $kws) {
            foreach ($kws as $kw) {
                if (str_contains($text, $kw)) { $domain = $d; break 2; }
            }
        }
        foreach (['critical', 'high', 'medium'] as $sev) {
            foreach (SEV_KW[$sev] as $kw) {
                if (str_contains($text, $kw)) { $severity = $sev; break 2; }
            }
        }

        // Step 2: Groq fallback only for fully ambiguous items
        if ($domain === 'geopolitics' && $severity === 'low' && strlen($title) > 20) {
            $r = self::rotate(
                "Classify: {$title}\nReturn JSON: {\"domain\":\"geopolitics|defense|economics|climate|cyber|society|technology\",\"severity\":\"critical|high|medium|low\",\"location\":\"country or city, empty if unknown\",\"confidence\":0.0-1.0}",
                'You are a JSON classifier. Return ONLY valid JSON. No markdown, no explanation.',
                true
            );
            if ($r) {
                try {
                    $d = json_decode($r, true, 5, JSON_THROW_ON_ERROR);
                    return [
                        'domain'     => $d['domain']     ?? $domain,
                        'severity'   => $d['severity']   ?? $severity,
                        'location'   => $d['location']   ?? '',
                        'confidence' => $d['confidence'] ?? 0.6,
                        'method'     => 'groq',
                    ];
                } catch (\Throwable) {}
            }
        }

        return ['domain' => $domain, 'severity' => $severity, 'location' => '', 'confidence' => 0.65, 'method' => 'kw'];
    }

    static function calls(): int { return self::$calls; }
}
