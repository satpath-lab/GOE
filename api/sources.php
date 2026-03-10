<?php
class Sources {

    //Country coordinates
    const GEO = [
        'Afghanistan'=>[33.9,67.7],'Albania'=>[41.1,20.2],'Algeria'=>[28.0,2.6],'Angola'=>[-11.2,17.9],
        'Argentina'=>[-38.4,-63.6],'Armenia'=>[40.1,45.0],'Australia'=>[-25.3,133.8],'Austria'=>[47.5,14.5],
        'Azerbaijan'=>[40.1,47.6],'Bangladesh'=>[23.7,90.4],'Belarus'=>[53.7,27.9],'Belgium'=>[50.5,4.5],
        'Brazil'=>[-14.2,-51.9],'Cambodia'=>[12.6,104.9],'Canada'=>[56.1,-106.3],'Chad'=>[15.5,18.7],
        'Chile'=>[-35.7,-71.5],'China'=>[35.9,104.2],'Colombia'=>[4.6,-74.1],'Congo'=>[0.2,15.8],
        'Cuba'=>[21.5,-79.8],'Egypt'=>[26.8,30.8],'Ethiopia'=>[9.1,40.5],'Finland'=>[61.9,25.7],
        'France'=>[46.2,2.2],'Germany'=>[51.2,10.5],'Ghana'=>[7.9,-1.0],'Greece'=>[39.1,21.8],
        'Haiti'=>[18.9,-72.3],'India'=>[20.6,79.0],'Indonesia'=>[-0.8,113.9],'Iran'=>[32.4,53.7],
        'Iraq'=>[33.2,43.7],'Ireland'=>[53.4,-8.2],'Israel'=>[31.0,34.9],'Italy'=>[41.9,12.6],
        'Japan'=>[36.2,138.3],'Jordan'=>[30.6,36.2],'Kazakhstan'=>[48.0,66.9],'Kenya'=>[-0.0,37.9],
        'Kosovo'=>[42.6,20.9],'Kuwait'=>[29.3,47.5],'Lebanon'=>[33.9,35.5],'Libya'=>[26.3,17.2],
        'Malaysia'=>[4.2,108.0],'Mali'=>[17.6,-3.9],'Mexico'=>[23.6,-102.6],'Moldova'=>[47.4,28.4],
        'Morocco'=>[31.8,-7.1],'Mozambique'=>[-18.7,35.5],'Myanmar'=>[17.0,96.1],'Nepal'=>[28.4,84.1],
        'Netherlands'=>[52.1,5.3],'Nigeria'=>[9.1,8.7],'North Korea'=>[40.3,127.5],'Norway'=>[60.5,8.5],
        'Pakistan'=>[30.4,69.3],'Palestine'=>[31.9,35.2],'Peru'=>[-9.2,-75.0],'Philippines'=>[12.9,122.0],
        'Poland'=>[51.9,19.1],'Qatar'=>[25.4,51.2],'Romania'=>[45.9,24.9],'Russia'=>[61.5,105.3],
        'Saudi Arabia'=>[24.2,45.1],'Serbia'=>[44.0,21.0],'Somalia'=>[5.2,46.2],
        'South Africa'=>[-30.6,22.9],'South Korea'=>[35.9,127.8],'South Sudan'=>[7.9,29.7],
        'Spain'=>[40.5,-3.7],'Sri Lanka'=>[7.9,80.7],'Sudan'=>[12.9,30.2],'Sweden'=>[60.1,18.6],
        'Syria'=>[34.8,38.9],'Taiwan'=>[23.6,120.9],'Thailand'=>[15.9,100.9],'Turkey'=>[38.9,35.2],
        'Uganda'=>[1.4,32.3],'UK'=>[55.4,-3.4],'Ukraine'=>[48.4,31.2],'USA'=>[37.1,-95.7],
        'Venezuela'=>[6.4,-66.6],'Vietnam'=>[14.1,108.3],'Yemen'=>[15.6,48.5],'Zimbabwe'=>[-19.0,29.2],
    ];

    static function geoLookup(string $text): ?array {
        $t = strtolower($text);
        foreach (self::GEO as $country => $coords) {
            if (str_contains($t, strtolower($country))) {
                return [
                    round($coords[0] + (mt_rand(-50, 50) / 100), 4),
                    round($coords[1] + (mt_rand(-50, 50) / 100), 4),
                ];
            }
        }
        return null;
    }

    //Master ingest
    static function ingestAll(): array {
        set_time_limit(120);  //2 minutes for full ingest
        $g = Graph::load();
        $added = 0;

        $sources = [
            'rss'        => fn() => self::rss(),
            'gdelt'      => fn() => self::gdelt(),
            'usgs'       => fn() => self::usgs(),
            'eonet'      => fn() => self::eonet(),
            'celestrak'  => fn() => self::celestrak(),
            'worldbank'  => fn() => self::worldbank(),
            'polymarket' => fn() => self::polymarket(),
            'nvd_cyber'  => fn() => self::nvdCyber(),
        ];

        $log = [];
        foreach ($sources as $name => $fn) {
            try {
                $events = $fn();
                $n = 0;
                foreach ($events as $ev) {
                    if (Graph::addNode($g, $ev)) {
                        Graph::link($g, $ev['id']);
                        $n++;
                        $added++;
                    }
                }
                $log[] = "{$name}(+{$n})";
            } catch (\Throwable $e) {
                error_log("GOE [{$name}]: " . $e->getMessage());
                $log[] = "{$name}(err)";
            }
        }

        Graph::save($g);

        $summary = [
            'nodes_added' => $added,
            'edges'       => count($g['edges']),
            'total_nodes' => count($g['nodes']),
            'sources'     => $log,
            'ts'          => date('c'),
        ];
        @file_put_contents(INGEST_FILE, json_encode($summary, JSON_PRETTY_PRINT));
        return $summary;
    }

    //RSS feeds multilingual
    private static function rss(): array {
        $feeds = [
            // English wire services
            ['https://feeds.reuters.com/reuters/worldNews',            'Reuters',    'en', 1],
            ['https://feeds.bbci.co.uk/news/world/rss.xml',           'BBC World',  'en', 1],
            ['https://rss.dw.com/rss/en-world',                       'DW',         'en', 1],
            ['https://www.aljazeera.com/xml/rss/all.xml',             'Al Jazeera', 'en', 1],
            ['https://rss.nytimes.com/services/xml/rss/nyt/World.xml','NYT',        'en', 1],
            // Defense
            ['https://www.defenseone.com/rss/all/',                   'DefenseOne', 'en', 2],
            ['https://www.thedrive.com/the-war-zone/rss',             'WarZone',    'en', 2],
            // India
            ['https://www.thehindu.com/news/international/?service=rss','The Hindu','en', 2],
            ['https://www.theprint.in/feed/',                         'ThePrint',   'en', 2],
            // Cyber/Tech
            ['https://feeds.feedburner.com/TheHackersNews',           'HackersNews','en', 2],
            // Economics
            ['https://www.ft.com/rss/home',                           'FT',         'en', 1],
        ];

        $events = [];
        foreach (array_slice($feeds, 0, 7) as [$url, $src, $lang, $tier]) {
            $raw = Http::get($url, 600);
            if (!$raw) continue;
            try {
                libxml_use_internal_errors(true);
                $feed = simplexml_load_string($raw);
                if (!$feed) continue;
                $items = $feed->channel->item ?? $feed->entry ?? [];
                $count = 0;
                foreach ($items as $item) {
                    if ($count >= 20) break;
                    $title = trim(strip_tags((string)($item->title ?? '')));
                    $desc  = trim(strip_tags((string)($item->description ?? $item->summary ?? '')));
                    $link  = (string)($item->link ?? '');
                    $pub   = (string)($item->pubDate ?? $item->published ?? '');
                    if (strlen($title) < 10) continue;

                    $c      = Groq::classifyEvent($title, substr($desc, 0, 300));
                    $coords = self::geoLookup($title . ' ' . $desc . ' ' . ($c['location'] ?? ''));

                    $events[] = [
                        'id'         => Graph::nid($title),
                        'title'      => $title,
                        'desc'       => substr($desc, 0, 350),
                        'url'        => $link,
                        'domain'     => $c['domain'],
                        'severity'   => $c['severity'],
                        'location'   => $c['location'],
                        'confidence' => $c['confidence'],
                        'source'     => $src,
                        'tier'       => $tier,
                        'lang'       => $lang,
                        'timestamp'  => date('Y-m-d H:i', strtotime($pub) ?: time()),
                        'icon'       => DOMAINS[$c['domain']]['icon'] ?? '📌',
                        'type'       => 'news',
                        'coords'     => $coords,
                    ];
                    $count++;
                }
            } catch (\Throwable) {}
        }
        return $events;
    }

    //GDELT (global, multilingual, 15-min cadence)
    private static function gdelt(): array {
        $queries = [
            ['military conflict war',          'defense'],
            ['geopolitical crisis diplomacy',  'geopolitics'],
            ['economic sanctions trade',       'economics'],
            ['cyber attack espionage breach',  'cyber'],
            ['natural disaster flood wildfire','climate'],
            ['India Pakistan China',           'geopolitics'],
            ['Russia Ukraine war',             'defense'],
            ['nuclear weapons missile launch', 'defense'],
            ['protest election coup',          'society'],
            ['artificial intelligence chip',   'technology'],
        ];

        $events = [];
        foreach (array_slice($queries, 0, 5) as [$q, $dom]) {
            $url = 'https://api.gdeltproject.org/api/v2/doc/doc?query='
                   . urlencode($q)
                   . '&mode=artlist&maxrecords=12&format=json&timespan=1440';
            $raw = Http::get($url, 1800);
            if (!$raw) continue;
            $data = json_decode($raw, true);
            foreach ($data['articles'] ?? [] as $a) {
                $title = $a['title'] ?? '';
                if (!$title) continue;
                $tone   = (float)($a['tone'] ?? 0);
                $sev    = $tone < -5 ? 'high' : ($tone < -2 ? 'medium' : 'low');
                $loc    = $a['sourcecountry'] ?? '';
                $coords = self::geoLookup($title . ' ' . $loc);
                $events[] = [
                    'id'         => Graph::nid($title),
                    'title'      => $title,
                    'url'        => $a['url'] ?? '',
                    'domain'     => $dom,
                    'severity'   => $sev,
                    'tone'       => round($tone, 2),
                    'location'   => $loc,
                    'confidence' => 0.75,
                    'source'     => 'GDELT',
                    'tier'       => 1,
                    'lang'       => $a['language'] ?? 'en',
                    'timestamp'  => date('Y-m-d H:i', strtotime($a['seendate'] ?? 'now') ?: time()),
                    'icon'       => DOMAINS[$dom]['icon'] ?? '📌',
                    'type'       => 'gdelt',
                    'coords'     => $coords,
                ];
            }
        }
        return $events;
    }

    //USGS Earthquakes
    private static function usgs(): array {
        $raw = Http::get(
            'https://earthquake.usgs.gov/fdsnws/event/1/query?format=geojson&minmagnitude=4.0&limit=25&orderby=time',
            300
        );
        if (!$raw) return [];
        $events = [];
        $data   = json_decode($raw, true);
        foreach ($data['features'] ?? [] as $f) {
            $p   = $f['properties'] ?? [];
            $mag = round((float)($p['mag'] ?? 0), 1);
            $plc = $p['place'] ?? 'Unknown';
            $c   = $f['geometry']['coordinates'] ?? [0, 0, 0];
            $sev = $mag >= 7 ? 'critical' : ($mag >= 6 ? 'high' : ($mag >= 5 ? 'medium' : 'low'));
            $events[] = [
                'id'        => 'eq_' . ($f['id'] ?? Graph::nid($mag . $plc)),
                'title'     => "M{$mag} earthquake — {$plc}",
                'domain'    => 'climate',
                'severity'  => $sev,
                'magnitude' => $mag,
                'depth_km'  => round((float)($c[2] ?? 0), 1),
                'location'  => $plc,
                'coords'    => [round((float)($c[1] ?? 0), 4), round((float)($c[0] ?? 0), 4)],
                'source'    => 'USGS',
                'tier'      => 1,
                'lang'      => 'en',
                'timestamp' => date('Y-m-d H:i', intval(($p['time'] ?? 0) / 1000) ?: time()),
                'icon'      => '🌋',
                'type'      => 'earthquake',
                'confidence'=> 1.0,
            ];
        }
        return $events;
    }

    //NASA EONET
    private static function eonet(): array {
        $raw = Http::get('https://eonet.gsfc.nasa.gov/api/v3/events?status=open&limit=20', 600);
        if (!$raw) return [];
        $iconMap = [
            'Wildfires'     => ['🔥', 'high'],
            'Severe Storms' => ['🌪️', 'medium'],
            'Volcanoes'     => ['🌋', 'high'],
            'Floods'        => ['🌊', 'medium'],
        ];
        $events = [];
        foreach (json_decode($raw, true)['events'] ?? [] as $ev) {
            $cat  = $ev['categories'][0]['title'] ?? 'Other';
            $geo  = $ev['geometry'][0] ?? [];
            $c    = $geo['coordinates'] ?? [0, 0];
            [$icon, $sev] = $iconMap[$cat] ?? ['⚠️', 'low'];
            $events[] = [
                'id'        => 'eonet_' . ($ev['id'] ?? Graph::nid($ev['title'] ?? '')),
                'title'     => $ev['title'] ?? 'Natural event',
                'domain'    => 'climate',
                'severity'  => $sev,
                'category'  => $cat,
                'location'  => $cat,
                'coords'    => [round((float)($c[1] ?? 0), 4), round((float)($c[0] ?? 0), 4)],
                'source'    => 'NASA EONET',
                'tier'      => 1,
                'lang'      => 'en',
                'timestamp' => date('Y-m-d H:i', strtotime($geo['date'] ?? 'now') ?: time()),
                'icon'      => $icon,
                'type'      => 'natural_event',
                'confidence'=> 0.9,
            ];
        }
        return $events;
    }

    //CelesTrak satellites
    private static function celestrak(): array {
        $groups = [['stations', '🛸', 'Space Station'], ['military', '🛰️', 'Military Sat']];
        $events = [];
        foreach ($groups as [$grp, $icon, $label]) {
            $raw = Http::get("https://celestrak.org/NORAD/elements/gp.php?GROUP={$grp}&FORMAT=json", 3600);
            if (!$raw) continue;
            foreach (array_slice(json_decode($raw, true) ?? [], 0, 4) as $s) {
                $name = $s['OBJECT_NAME'] ?? 'Unknown';
                $nid  = (string)($s['NORAD_CAT_ID'] ?? '');
                $events[] = [
                    'id'       => 'sat_' . $nid,
                    'title'    => "{$label}: {$name} (#NORAD{$nid})",
                    'domain'   => 'defense',
                    'severity' => 'low',
                    'norad_id' => $nid,
                    'group'    => $grp,
                    'epoch'    => $s['EPOCH'] ?? '',
                    'location' => '',
                    'source'   => 'CelesTrak',
                    'tier'     => 1,
                    'lang'     => 'en',
                    'timestamp'=> date('Y-m-d H:i', strtotime($s['EPOCH'] ?? 'now') ?: time()),
                    'icon'     => $icon,
                    'type'     => 'satellite',
                    'confidence'=> 1.0,
                    'coords'   => null,
                ];
            }
        }
        return $events;
    }

    //World Bank economic indicators
    private static function worldbank(): array {
        $isos = ['IN', 'CN', 'RU', 'US', 'PK', 'IR', 'UA', 'DE', 'FR', 'TR', 'SA', 'BR', 'NG'];
        $events = [];
        foreach (array_slice($isos, 0, 8) as $iso) {
            $url = "https://api.worldbank.org/v2/country/{$iso}/indicator/FP.CPI.TOTL.ZG?format=json&mrv=1&per_page=1";
            $raw = Http::get($url, 86400);
            if (!$raw) continue;
            try {
                $data = json_decode($raw, true);
                $row  = ($data[1] ?? [])[0] ?? null;
                if (!$row || $row['value'] === null) continue;
                $val  = round((float)$row['value'], 2);
                $cName= $row['country']['value'] ?? $iso;
                $sev  = $val > 20 ? 'high' : ($val > 10 ? 'medium' : 'low');
                $events[] = [
                    'id'         => Graph::nid($iso . 'cpi'),
                    'title'      => "{$cName}: Inflation CPI = {$val}%",
                    'domain'     => 'economics',
                    'severity'   => $sev,
                    'value'      => $val,
                    'country_iso'=> $iso,
                    'location'   => $cName,
                    'coords'     => self::geoLookup($cName),
                    'source'     => 'World Bank',
                    'tier'       => 1,
                    'lang'       => 'en',
                    'timestamp'  => date('Y-m-d H:i'),
                    'icon'       => '📊',
                    'type'       => 'indicator',
                    'confidence' => 1.0,
                ];
            } catch (\Throwable) {}
        }
        return $events;
    }

    //Polymarket
    private static function polymarket(): array {
        $raw = Http::get('https://clob.polymarket.com/markets?tag_slug=geopolitics&limit=10&order=volume&ascending=false', 300);
        if (!$raw) return [];
        $events = [];
        foreach (json_decode($raw, true)['data'] ?? [] as $m) {
            $q    = $m['question'] ?? '';
            if (!$q) continue;
            $prob = round((float)(($m['outcomePrices'] ?? ['0.5'])[0]) * 100, 1);
            $sev  = $prob > 75 ? 'high' : ($prob > 50 ? 'medium' : 'low');
            $events[] = [
                'id'          => 'poly_' . Graph::nid($q),
                'title'       => "Prediction: {$q} ({$prob}% YES)",
                'domain'      => 'geopolitics',
                'severity'    => $sev,
                'probability' => $prob,
                'location'    => '',
                'coords'      => self::geoLookup($q),
                'source'      => 'Polymarket',
                'tier'        => 2,
                'lang'        => 'en',
                'timestamp'   => date('Y-m-d H:i'),
                'icon'        => '🔮',
                'type'        => 'prediction',
                'confidence'  => 0.7,
            ];
        }
        return $events;
    }

    //NIST NVD Critical CVEs
    private static function nvdCyber(): array {
        $raw = Http::get(
            'https://services.nvd.nist.gov/rest/json/cves/2.0?cvssV3Severity=CRITICAL&resultsPerPage=10',
            1800
        );
        if (!$raw) return [];
        $events = [];
        try {
            $data = json_decode($raw, true);
            foreach ($data['vulnerabilities'] ?? [] as $item) {
                $cve  = $item['cve'] ?? [];
                $cid  = $cve['id'] ?? '';
                $desc = '';
                foreach ($cve['descriptions'] ?? [] as $d) {
                    if ($d['lang'] === 'en') { $desc = $d['value']; break; }
                }
                if (!$cid) continue;
                $events[] = [
                    'id'        => Graph::nid($cid),
                    'title'     => "CRITICAL CVE: {$cid} — " . substr($desc, 0, 100),
                    'desc'      => substr($desc, 0, 300),
                    'domain'    => 'cyber',
                    'severity'  => 'critical',
                    'cve_id'    => $cid,
                    'location'  => '',
                    'coords'    => null,
                    'source'    => 'NIST NVD',
                    'tier'      => 1,
                    'lang'      => 'en',
                    'timestamp' => date('Y-m-d H:i'),
                    'icon'      => '⚡',
                    'type'      => 'cve',
                    'confidence'=> 1.0,
                ];
            }
        } catch (\Throwable) {}
        return $events;
    }
}
