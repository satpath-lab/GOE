<?php
class Http {

     //GET with file-based cache
    static function get(string $url, int $ttl = CACHE_TTL, array $headers = []): ?string {
        @mkdir(CACHE_DIR, 0755, true);
        $cf = CACHE_DIR . '/' . substr(md5($url), 0, 16) . '.hc';

        //serve from cache if fresh
        if (file_exists($cf) && (time() - filemtime($cf)) < $ttl) {
            return file_get_contents($cf) ?: null;
        }

        $defaultHeaders = [
            'User-Agent: GOE/2.1 (Global Ontology Engine research)',
            'Accept: application/json, application/xml, text/xml, */*',
            'Connection: close',
        ];
        foreach ($headers as $k => $v) {
            $defaultHeaders[] = "$k: $v";
        }

        $ctx = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'header'          => implode("\r\n", $defaultHeaders),
                'timeout'         => HTTP_TIMEOUT,
                'follow_location' => 1,
                'max_redirects'   => 3,
                'ignore_errors'   => true,
            ],
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
            ],
        ]);

        $body = @file_get_contents($url, false, $ctx);
        if ($body !== false && strlen($body) > 10) {
            file_put_contents($cf, $body);
            return $body;
        }
        return null;
    }

    
    static function postJson(string $url, array $payload, array $headers = []): ?string {
        $data = json_encode($payload);
        $allHeaders = array_merge([
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data),
            'Connection: close',
        ], $headers);

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $allHeaders),
                'content'       => $data,
                'timeout'       => 25,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        return ($raw !== false && strlen($raw) > 2) ? $raw : null;
    }

    // Cache key path
    static function cacheFile(string $key): string {
        return CACHE_DIR . '/' . substr(md5($key), 0, 16) . '.gc';
    }

    static function cacheGet(string $path): ?string {
        if (!file_exists($path) || (time() - filemtime($path)) > CACHE_TTL) return null;
        $d = json_decode(file_get_contents($path), true);
        return $d['v'] ?? null;
    }

    static function cacheSet(string $path, string $value): void {
        @mkdir(CACHE_DIR, 0755, true);
        file_put_contents($path, json_encode(['v' => $value, 't' => time()]));
    }
}
