<?php
//define('GOE_VER', '2.1');
require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/http.php';
require_once __DIR__ . '/api/graph.php';
require_once __DIR__ . '/api/groq.php';
require_once __DIR__ . '/api/sources.php';
require_once __DIR__ . '/api/intelligence.php';


@mkdir(CACHE_DIR, 0755, true);
@mkdir(dirname(GRAPH_FILE), 0755, true);

if (isset($_GET['api'])) {
    error_reporting(0); 
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    $j = fn($d) => print json_encode(['ok' => true,  'data'  => $d], JSON_UNESCAPED_UNICODE);
    $e = fn($m) => print json_encode(['ok' => false, 'error' => $m]);
    try {
        match ($_GET['api']) {
            'status'  => $j(Graph::stats()),
            'ingest'  => $j(Intel::ingestNow()),
            'brief'   => $j(Intel::brief(Graph::load())),
            'query'   => (function() use ($j) {
                $q = trim($_GET['q'] ?? '');
                if (!$q) throw new \InvalidArgumentException('No query');
                $j(Intel::query($q, Graph::load()));
            })(),
            'domain'  => $j(Graph::byDomain(Graph::load(), $_GET['d'] ?? 'geopolitics')),
            'mapdata' => $j(Graph::withCoords(Graph::load())),
            'cii'     => $j(Intel::cii(Graph::load())),
            'graph'   => (function() use ($j) {
                $g = Graph::load();
                unset($g['edges']);   // Too long
                $j($g);
            })(),
            default   => throw new \InvalidArgumentException('Unknown api'),
        };
    } catch (\Throwable $ex) {
        http_response_code(500);
        $e($ex->getMessage());
    }
    exit;
}

$g        = Graph::load();
$st       = Graph::stats();
$nc       = $st['nodes'];
$ec       = $st['edges'];
$upd      = $st['updated'];
$initFeed = Graph::byDomain($g, 'geopolitics');
$geoNodes = Graph::withCoords($g);
$geoJson  = json_encode(array_slice($geoNodes, 0, 1000), JSON_UNESCAPED_UNICODE);
$byd      = $st['by_domain'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>GOE — Global Intelligence Engine</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&family=JetBrains+Mono:wght@300;400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
*,::before,::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#030508;--s1:#070c12;--s2:#0c1520;--s3:#111e2c;--s4:#172638;
  --border:rgba(255,255,255,0.048);--gbo:rgba(0,200,100,0.22);
  --g:#00ff88;--g2:rgba(0,255,136,0.07);--g3:rgba(0,255,136,0.12);
  --a:#f5a623;--a2:rgba(245,166,35,0.07);
  --r:#ff4757;--r2:rgba(255,71,87,0.07);
  --b:#4fc3f7;--b2:rgba(79,195,247,0.07);
  --cy:#b39ddb;--cy2:rgba(179,157,219,0.07);
  --te:#69f0ae;--te2:rgba(105,240,174,0.07);
  --cl:#26c6da;--cl2:rgba(38,198,218,0.07);
  --t0:#dceaf4;--t1:#7a9db8;--t2:#3d5570;
  --sans:'Space Grotesk',system-ui,sans-serif;
  --mono:'JetBrains Mono',monospace;
}
html{font-size:13.5px;scroll-behavior:smooth}
body{background:var(--bg);color:var(--t0);font-family:var(--sans);line-height:1.55;min-height:100vh;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;background:repeating-linear-gradient(0deg,rgba(0,0,0,.016) 0,rgba(0,0,0,.016) 1px,transparent 1px,transparent 3px);pointer-events:none;z-index:9998}
::-webkit-scrollbar{width:10px;height:3px}::-webkit-scrollbar-track{background:var(--s1)}::-webkit-scrollbar-thumb{background:var(--s4)}
.w{max-width:1700px;margin:0 auto;padding:0 16px}
.g2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.g3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.tb{height:50px;background:rgba(3,5,8,.95);border-bottom:1px solid var(--gbo);
    display:flex;align-items:center;justify-content:space-between;
    padding:0 16px;position:sticky;top:0;z-index:1000;
    backdrop-filter:blur(16px);gap:12px}
.tbl{display:flex;align-items:center;gap:10px}
.tbmark{width:30px;height:30px;position:relative;flex-shrink:0}
.tbmark svg{width:100%;height:100%}
.tbname{font-family:var(--mono);font-size:14px;font-weight:700;color:var(--g);letter-spacing:.5px}
.tbsub{font-family:var(--mono);font-size:8px;color:var(--t2);letter-spacing:2.5px;text-transform:uppercase;margin-top:1px}
.tbm{display:flex;align-items:center;gap:18px;flex:1;justify-content:center}
.pdot{width:5px;height:5px;border-radius:50%;flex-shrink:0;animation:pdot 2s ease-in-out infinite}
.pdot.g{background:var(--g)} .pdot.a{background:var(--a)} .pdot.r{background:var(--r)}
@keyframes pdot{0%,100%{opacity:1;box-shadow:0 0 0 0 rgba(0,255,136,.3)}50%{opacity:.6;box-shadow:0 0 0 5px transparent}}
.tbs{font-family:var(--mono);font-size:9.5px;color:var(--t2);letter-spacing:.3px;display:flex;align-items:center;gap:5px}
.tbs strong{color:var(--t1);font-weight:500}
.tbr{display:flex;gap:7px}
.btn{font-family:var(--mono);font-size:9px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;
     padding:6px 13px;border:1px solid;cursor:pointer;display:inline-flex;align-items:center;gap:5px;
     transition:all .14s;border-radius:2px;background:transparent;white-space:nowrap}
.btn-g{color:var(--g);border-color:rgba(0,200,100,.3)}.btn-g:hover{background:var(--g2)}
.btn-a{color:var(--a);border-color:rgba(245,166,35,.28)}.btn-a:hover{background:var(--a2)}
.btn-q{color:var(--t2);border-color:var(--border)}.btn-q:hover{color:var(--t1);border-color:rgba(255,255,255,.1)}
.btn:disabled{opacity:.28;cursor:not-allowed}
.hstrip{display:grid;grid-template-columns:repeat(6,1fr);gap:1px;background:var(--border);margin:10px 0}
.hsc{background:var(--s1);padding:13px 15px}
.hsv{font-family:var(--mono);font-size:22px;font-weight:700;color:var(--t0);line-height:1}
.hsv em{font-size:10px;color:var(--g);font-style:normal;margin-left:2px}
.hsl{font-family:var(--mono);font-size:7.5px;letter-spacing:2px;text-transform:uppercase;color:var(--t2);margin-top:3px}
.dtabs{display:grid;grid-template-columns:repeat(7,1fr);gap:1px;background:var(--border);margin-bottom:12px}
.dtab{background:var(--s1);padding:11px 8px;cursor:pointer;border-top:2px solid transparent;
      transition:all .14s;text-align:center}
.dtab:hover,.dtab.on{background:var(--s2);border-top-color:var(--g)}
.dtab.on .dtn{color:var(--g)}
.dti{font-size:17px;margin-bottom:5px}
.dtn{font-family:var(--mono);font-size:8.5px;font-weight:700;color:var(--t1);letter-spacing:.3px;text-transform:uppercase}
.dtc{font-family:var(--mono);font-size:8px;color:var(--t2);margin-top:2px}
.card{background:var(--s1);border:1px solid var(--border);overflow:hidden}
.ch{padding:10px 13px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:8px}
.ct{font-family:var(--mono);font-size:8px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--g)}
.cb{padding:12px 13px}
.cf{padding:7px 13px;border-top:1px solid var(--border)}

#mapwrap{position:relative;height:480px;background:var(--s1);border:1px solid var(--border);overflow:hidden}
#map{width:100%;height:100%}
.mfilt{position:absolute;top:8px;left:8px;z-index:900;
  background:rgba(3,5,8,.92);border:1px solid var(--gbo);padding:9px 12px;
  backdrop-filter:blur(8px);display:flex;flex-direction:column;gap:5px;max-width:220px}
.mfilt-title{font-family:var(--mono);font-size:7px;letter-spacing:2.5px;text-transform:uppercase;color:var(--t2);margin-bottom:3px}
.mfbtn{font-family:var(--mono);font-size:8px;letter-spacing:.3px;padding:3px 8px;
  border:1px solid var(--border);color:var(--t2);cursor:pointer;
  transition:all .13s;text-transform:uppercase;background:transparent;text-align:left}
.mfbtn.on{color:var(--g);border-color:var(--gbo);background:var(--g2)}
.mleg{position:absolute;bottom:8px;right:8px;z-index:900;
  background:rgba(3,5,8,.92);border:1px solid var(--border);padding:10px 12px;
  backdrop-filter:blur(8px);min-width:105px}
.mleg-t{font-family:var(--mono);font-size:7px;letter-spacing:2px;text-transform:uppercase;color:var(--t2);margin-bottom:7px}
.mlegr{display:flex;align-items:center;gap:6px;margin-bottom:4px;font-family:var(--mono);font-size:8.5px;color:var(--t1)}
.mlegdot{width:9px;height:9px;border-radius:50%;flex-shrink:0;border:1.5px solid rgba(255,255,255,.2)}
.mpop{position:absolute;top:8px;right:8px;z-index:900;
  background:rgba(3,5,8,.94);border:1px solid var(--gbo);padding:12px 14px;
  min-width:230px;max-width:310px;display:none;backdrop-filter:blur(8px)}
.mpop.on{display:block}
.mpopt{font-size:11.5px;font-weight:600;color:var(--t0);margin-bottom:8px;line-height:1.4}
.mpkv{display:grid;grid-template-columns:auto 1fr;gap:2px 10px}
.mpk{font-family:var(--mono);font-size:8.5px;color:var(--t2)}
.mpv{font-family:var(--mono);font-size:8.5px;color:var(--t1)}

.leaflet-container{background:#030508!important}
.leaflet-control-attribution{display:none!important}
.leaflet-control-zoom a{background:var(--s2)!important;color:var(--t1)!important;border-color:var(--border)!important;font-size:14px!important}

.elist{display:flex;flex-direction:column}
.ei{display:grid;grid-template-columns:17px 1fr auto;gap:8px;align-items:start;
    padding:9px 11px;border-left:2px solid transparent;cursor:pointer;
    transition:background .12s,border-color .12s}
.ei:hover{background:var(--s2)}.ei+.ei{border-top:1px solid var(--border)}
.ei.c-critical{border-left-color:var(--r)}.ei.c-high{border-left-color:var(--a)}
.ei.c-medium{border-left-color:var(--b)}.ei.c-low{border-left-color:var(--t2)}
.eiic{font-size:12.5px;line-height:1.6}
.eiti{font-size:11.5px;color:var(--t0);font-weight:500;line-height:1.4;margin-bottom:2px}
.eime{display:flex;flex-wrap:wrap;gap:4px;align-items:center}
.dtag{font-family:var(--mono);font-size:7px;letter-spacing:.3px;padding:1px 4px;border-radius:1px;text-transform:uppercase;font-weight:600}
.d-geopolitics{color:var(--a);background:var(--a2)}.d-defense{color:var(--r);background:var(--r2)}
.d-economics{color:var(--g);background:var(--g2)}.d-climate{color:var(--cl);background:var(--cl2)}
.d-cyber{color:var(--cy);background:var(--cy2)}.d-society{color:var(--b);background:var(--b2)}
.d-technology{color:var(--te);background:var(--te2)}
.eisrc{font-family:var(--mono);font-size:8px;color:var(--t2)}
.eits{font-family:var(--mono);font-size:7.5px;color:var(--t2);white-space:nowrap}
.sv{font-family:var(--mono);font-size:7.5px;font-weight:700;text-transform:uppercase;white-space:nowrap}
.sv.critical{color:var(--r)}.sv.high{color:var(--a)}.sv.medium{color:var(--b)}.sv.low{color:var(--t2)}
.rbox{background:var(--s2);border:1px solid var(--border);padding:12px 13px;
  font-size:12.5px;color:var(--t0);line-height:1.8;min-height:68px;white-space:pre-wrap}
.rbox.load{opacity:.38}
.cur{display:inline-block;width:6px;height:12px;background:var(--g);
     animation:cur .75s step-end infinite;margin-left:2px;vertical-align:middle}
@keyframes cur{0%,100%{opacity:1}50%{opacity:0}}
.qrow{display:flex;gap:7px;margin-bottom:10px}
.qin{flex:1;background:var(--s2);border:1px solid var(--border);color:var(--t0);
     font-family:var(--mono);font-size:11px;padding:8px 11px;outline:none;
     transition:border-color .14s}
.qin:focus{border-color:var(--gbo)}
.qin::placeholder{color:var(--t2)}
.chips{display:flex;flex-wrap:wrap;gap:5px;margin-top:9px}
.chip{font-family:var(--mono);font-size:8px;padding:3px 8px;border:1px solid var(--border);
      color:var(--t2);cursor:pointer;transition:all .13s;background:transparent}
.chip:hover{border-color:var(--gbo);color:var(--g)}
.ciit{width:100%;border-collapse:collapse}
.ciit th{font-family:var(--mono);font-size:7.5px;letter-spacing:2px;text-transform:uppercase;
  color:var(--g);opacity:.5;padding:7px 9px;text-align:left;border-bottom:1px solid var(--gbo)}
.ciit td{padding:6px 9px;border-bottom:1px solid var(--border);font-size:11px;color:var(--t2);vertical-align:middle}
.ciit tr:hover td{background:var(--s2)}
.ciit td.cn{font-family:var(--mono);font-size:10.5px;color:var(--t0);font-weight:500}
.bw{display:flex;align-items:center;gap:7px}
.bar{height:3px;background:var(--border);flex:1;min-width:44px}
.barf{height:100%}
.b0{background:var(--t2)}.b1{background:var(--a)}.b2{background:#ff7043}.b3{background:var(--r)}
.bv{font-family:var(--mono);font-size:9.5px;font-weight:700;min-width:22px;text-align:right}
.bv0{color:var(--t2)}.bv1{color:var(--a)}.bv2{color:#ff7043}.bv3{color:var(--r)}
.tag{font-family:var(--mono);font-size:7px;letter-spacing:.5px;padding:2px 5px;
     border:1px solid var(--border);color:var(--t2);text-transform:uppercase;display:inline-block}
.tag-g{background:var(--g2);border-color:var(--gbo);color:var(--g)}
.tag-a{background:var(--a2);border-color:rgba(245,166,35,.22);color:var(--a)}
.tag-r{background:var(--r2);border-color:rgba(255,71,87,.18);color:var(--r)}
.log{font-family:var(--mono);font-size:9.5px;line-height:2;max-height:185px;overflow-y:auto}
.ll{display:flex;gap:9px;border-bottom:1px solid var(--border)}
.lts{color:var(--t2);flex-shrink:0}
.lm.ok{color:var(--g)}.lm.warn{color:var(--a)}.lm.err{color:var(--r)}.lm.info{color:var(--b)}
#np{display:none}#np.show{display:block;margin-bottom:12px}
.nkv{display:grid;grid-template-columns:auto 1fr;gap:3px 11px}
.nk{font-family:var(--mono);font-size:9px;color:var(--t2)}
.nv{font-family:var(--mono);font-size:9px;color:var(--t1)}
.erow{display:flex;flex-wrap:wrap;gap:3px;margin-top:8px}
.ec{font-family:var(--mono);font-size:7px;padding:2px 6px;border:1px solid var(--border);color:var(--t2);cursor:pointer;transition:all .13s}
.ec:hover{border-color:var(--gbo);color:var(--g)}
.sh{font-family:var(--mono);font-size:8px;font-weight:700;letter-spacing:3px;text-transform:uppercase;
    color:var(--t2);margin-bottom:9px;display:flex;align-items:center;gap:9px}
.sh::after{content:'';flex:1;height:1px;background:var(--border)}
.sh span{font-family:var(--mono);font-size:7px;padding:2px 6px;border:1px solid var(--gbo);color:var(--g);background:var(--g2);margin-left:3px}

.sec{padding:14px 0}
.sep{height:1px;background:var(--border);margin:10px 0}
.flex{display:flex;align-items:center;gap:7px}
.mono{font-family:var(--mono)}

@media(max-width:1200px){.hstrip{grid-template-columns:repeat(3,1fr)}.dtabs{grid-template-columns:repeat(4,1fr)}}
@media(max-width:900px){.g2{grid-template-columns:1fr}.dtabs{grid-template-columns:repeat(3,1fr)}.tbr .btn-q{display:none}}
@media(max-width:600px){#mapwrap{height:300px}.dtabs{grid-template-columns:repeat(2,1fr)}.hstrip{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>

<nav class="tb">
  <div class="tbl">
    <div class="tbmark">
      <svg viewBox="0 0 30 30" fill="none" xmlns="http://www.w3.org/2000/svg">
        <circle cx="15" cy="15" r="13" stroke="#00ff88" stroke-width="1.2" stroke-dasharray="4 2"/>
        <circle cx="15" cy="15" r="7" stroke="#00ff88" stroke-width="1"/>
        <circle cx="15" cy="15" r="2.5" fill="#00ff88"/>
        <line x1="15" y1="2" x2="15" y2="8" stroke="#00ff88" stroke-width="1.2"/>
        <line x1="15" y1="22" x2="15" y2="28" stroke="#00ff88" stroke-width="1.2"/>
        <line x1="2" y1="15" x2="8" y2="15" stroke="#00ff88" stroke-width="1.2"/>
        <line x1="22" y1="15" x2="28" y2="15" stroke="#00ff88" stroke-width="1.2"/>
      </svg>
    </div>
    <div>
      <div class="tbname">GOE</div>
      <div class="tbsub">Global Ontology Engine</div>
    </div>
  </div>
  <div class="tbm">
    <div class="tbs"><span class="pdot g"></span>LIVE</div>
    <div class="tbs" id="tbs-upd"><strong id="upd-v"><?= $upd ? date('d M H:i', strtotime($upd)) : '-' ?></strong>&nbsp;SYNC</div>
    <div class="tbs"><strong id="tn"><?= $nc ?></strong>&nbsp;NODES</div>
    <div class="tbs"><strong id="te"><?= $ec ?></strong>&nbsp;EDGES</div>
    <div class="tbs"><strong id="tg"><?= count($geoNodes) ?></strong>&nbsp;GEO</div>
  </div>
  <div class="tbr">
    <button class="btn btn-g" id="btn-sync" onclick="doSync()">SYNC</button>
    <button class="btn btn-a" id="btn-brief" onclick="doBrief()">BRIEF</button>
    <button class="btn btn-q" onclick="doStatus()">STATUS</button>
  </div>
</nav>

<div class="w">

<div class="hstrip">
  <div class="hsc"><div class="hsv" id="sv-n"><?= $nc ?><em>N</em></div><div class="hsl">Nodes</div></div>
  <div class="hsc"><div class="hsv" id="sv-e"><?= $ec ?><em>E</em></div><div class="hsl">Edges</div></div>
  <div class="hsc"><div class="hsv"><?= count(DOMAINS) ?><em>D</em></div><div class="hsl">Domains</div></div>
  <div class="hsc"><div class="hsv" id="sv-g"><?= count($geoNodes) ?><em>P</em></div><div class="hsl">Map Points</div></div>
  <div class="hsc"><div class="hsv" id="sv-c"><?= $st['cache'] ?><em>C</em></div><div class="hsl">Cached</div></div>
  <div class="hsc"><div class="hsv" id="sv-s"><?= count($st['by_domain']) ?><em>S</em></div><div class="hsl">Sources</div></div>
</div>

<div class="dtabs">
  <?php foreach (DOMAINS as $key => $m):
    $cnt = $byd[$key] ?? 0; ?>
  <div class="dtab <?= $key === 'geopolitics' ? 'on' : '' ?>" id="dt-<?= $key ?>" onclick="selD('<?= $key ?>')">
    <div class="dti"><?= $m['icon'] ?></div>
    <div class="dtn"><?= $m['label'] ?></div>
    <div class="dtc" id="dn-<?= $key ?>"><?= $cnt ?></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="sec" style="padding-top:0">
  <div class="sh">Live Intelligence Map <span>REAL-TIME</span></div>
  <div id="mapwrap">
    <div id="map"></div>

    <div class="mfilt">
      <div class="mfilt-title">Filter by Domain</div>
      <?php foreach (DOMAINS as $k => $m): ?>
      <button class="mfbtn on" data-d="<?= $k ?>" onclick="togF('<?= $k ?>')"><?= $m['icon'] ?> <?= $m['label'] ?></button>
      <?php endforeach; ?>
      <button class="mfbtn" onclick="togAll()" style="margin-top:3px;border-top:1px solid var(--border);padding-top:6px;color:var(--g)">◈ Toggle All</button>
    </div>

    <div class="mleg">
      <div class="mleg-t">Severity</div>
      <div class="mlegr"><span class="mlegdot" style="background:#ff4757"></span>Critical</div>
      <div class="mlegr"><span class="mlegdot" style="background:#f5a623"></span>High</div>
      <div class="mlegr"><span class="mlegdot" style="background:#4fc3f7"></span>Medium</div>
      <div class="mlegr"><span class="mlegdot" style="background:#3d5570"></span>Low</div>
    </div>


    <div class="mpop" id="mpop">
      <div style="display:flex;justify-content:space-between;align-items:start">
        <div class="mpopt" id="mpopt">—</div>
        <button onclick="$('mpop').classList.remove('on')" style="font-family:var(--mono);font-size:9px;color:var(--t2);background:none;border:none;cursor:pointer;flex-shrink:0;margin-left:8px">✕</button>
      </div>
      <div class="mpkv" id="mpkv"></div>
    </div>
  </div>
</div>


<div class="g2" style="margin-bottom:12px">

  <div>
    <div class="sh">Event Graph</div>
    <div class="card">
      <div class="ch">
        <span class="ct" id="ftitle">Geopolitics</span>
        <div class="flex"><span class="tag tag-g">LIVE</span><span class="tag" id="fcnt"><?= count($initFeed) ?></span></div>
      </div>
      <div style="max-height:430px;overflow-y:auto">
        <div class="elist" id="elist">
          <?php if (empty($initFeed)): ?>
            <div style="padding:28px;text-align:center;color:var(--t2);font-family:var(--mono);font-size:9px;line-height:2.2">
              NO DATA · CLICK ⟳ SYNC<br>
              <span style="color:var(--g)">Sources: Reuters, BBC, GDELT, USGS, NASA, NVD…</span>
            </div>
          <?php else: foreach (array_slice($initFeed, 0, 25) as $n): ?>
            <div class="ei c-<?= htmlspecialchars($n['severity'] ?? 'low') ?>" onclick="showNode('<?= htmlspecialchars($n['id'] ?? '') ?>')">
              <span class="eiic"><?= htmlspecialchars($n['icon'] ?? '📌') ?></span>
              <div>
                <div class="eiti"><?= htmlspecialchars(mb_substr($n['title'] ?? '', 0, 118)) ?></div>
                <div class="eime">
                  <span class="dtag d-<?= htmlspecialchars($n['domain'] ?? '') ?>"><?= htmlspecialchars($n['domain'] ?? '') ?></span>
                  <span class="eisrc"><?= htmlspecialchars($n['source'] ?? '') ?></span>
                  <span class="eits"><?= htmlspecialchars(substr($n['timestamp'] ?? '', 0, 16)) ?></span>
                </div>
              </div>
              <span class="sv <?= htmlspecialchars($n['severity'] ?? 'low') ?>"><?= strtoupper($n['severity'] ?? 'LOW') ?></span>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
      <div class="cf flex" style="justify-content:space-between">
        <span class="mono" style="font-size:8.5px;color:var(--t2)">Sorted: severity → recency</span>
        <button class="btn btn-q" style="padding:3px 8px;font-size:8px" onclick="loadFeed(curD)">↻</button>
      </div>
    </div>
  </div>


  <div style="display:flex;flex-direction:column;gap:12px">

    <div>
      <div class="sh">AI World Brief</div>
      <div class="card">
        <div class="ch">
          <span class="ct">Intelligence Briefing</span>
          <span class="tag mono" id="bmodel" style="font-size:7px"><?= MODEL_REASON ?></span>
        </div>
        <div class="cb">
          <div class="rbox" id="bbox">Click <strong style="color:var(--g)">BRIEF</strong> to generate a live AI intelligence briefing from the knowledge graph.</div>
        </div>
      </div>
    </div>

    <!-- NL Query -->
    <div>
      <div class="sh">Intelligence Query</div>
      <div class="card">
        <div class="ch">
          <span class="ct">Ask the Graph</span>
          <span class="tag tag-g">GRAPH-GROUNDED</span>
        </div>
        <div class="cb">
          <div class="qrow">
            <input class="qin" id="qin" placeholder="e.g. What military threats face India? Active cyber attacks?" onkeydown="if(event.key==='Enter')doQuery()">
            <button class="btn btn-g" onclick="doQuery()">→</button>
          </div>
          <div class="rbox" id="qbox" style="min-height:55px">Ask anything about the global intelligence graph. Answers are grounded strictly in graph data.</div>
          <div class="chips">
            <button class="chip" onclick="qq('What are the top conflict hotspots right now?')">Top hotspots</button>
            <button class="chip" onclick="qq('What military threats face India from Pakistan or China?')">India threats</button>
            <button class="chip" onclick="qq('What critical cyber vulnerabilities are active?')">Cyber CVEs</button>
            <button class="chip" onclick="qq('What natural disasters occurred recently?')">Disasters</button>
            <button class="chip" onclick="qq('Summarise Russia Ukraine latest')">Russia-Ukraine</button>
            <button class="chip" onclick="qq('What economic instability signals exist?')">Economic risk</button>
            <button class="chip" onclick="qq('What technology developments have strategic importance?')">Tech signals</button>
            <button class="chip" onclick="qq('What is happening in the Middle East?')">Middle East</button>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<div id="np" class="card">
  <div class="ch">
    <span class="ct" id="npt">Node Detail</span>
    <button class="btn btn-q" style="padding:3px 8px;font-size:8px" onclick="$('np').classList.remove('show')">Close</button>
  </div>
  <div class="cb" id="npb"></div>
</div>

<div class="g2" style="margin-bottom:20px">

  <div>
    <div class="sh">Country Instability Index <span>CII</span></div>
    <div class="card">
      <div class="ch">
        <span class="ct">CII — 30 Countries</span>
        <button class="btn btn-q" style="padding:3px 8px;font-size:8px" onclick="doCII()">Compute</button>
      </div>
      <div style="max-height:350px;overflow-y:auto">
        <table class="ciit">
          <thead><tr><th>Country</th><th>Score</th><th>Δ</th><th>Signals</th></tr></thead>
          <tbody id="ciibody"><tr><td colspan="4" style="text-align:center;padding:18px;font-family:var(--mono);font-size:9px;color:var(--t2)">Click ↻ Compute to calculate from live graph data</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <div>
    <div class="sh">System Log</div>
    <div class="card">
      <div class="ch">
        <span class="ct">Live Operations Log</span>
        <button class="btn btn-q" style="padding:3px 8px;font-size:8px" onclick="clrLog()">✕ Clear</button>
      </div>
      <div class="cb" style="padding:8px 11px">
        <div class="log" id="log">
          <div class="ll"><span class="lts"><?= date('H:i:s') ?></span><span class="lm ok">GOE v<?= GOE_VER ?> · PHP <?= PHP_VERSION ?> · Zero deprecated warnings</span></div>
          <div class="ll"><span class="lts"><?= date('H:i:s') ?></span><span class="lm info">Graph: <?= $nc ?>N / <?= $ec ?>E / <?= count($geoNodes) ?> geo-points / <?= $st['cache'] ?> cached</span></div>
          <?php if (!$upd): ?>
          <div class="ll"><span class="lts"><?= date('H:i:s') ?></span><span class="lm warn">No data — click SYNC (runs in ~30s)</span></div>
          <?php else: ?>
          <div class="ll"><span class="lts"><?= date('H:i:s') ?></span><span class="lm info">Last sync: <?= htmlspecialchars($upd) ?></span></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

</div>
</div>

<script>
const $ = id => document.getElementById(id);
let curD = 'geopolitics';
let map, mLayer, allMarkers = {}, activeD = new Set(<?= json_encode(array_keys(DOMAINS)) ?>);
const DCOLS = <?= json_encode(array_combine(array_keys(DOMAINS), array_column(DOMAINS, 'color'))) ?>;
const SEVCO = {critical:'#ff4757',high:'#f5a623',medium:'#4fc3f7',low:'#3d5570'};

function initMap() {
  map = L.map('map', {center:[20,10],zoom:2,minZoom:2,maxZoom:10,attributionControl:false,zoomControl:true});
  L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
    {maxZoom:19,subdomains:'abcd'}).addTo(map);
  mLayer = L.layerGroup().addTo(map);
  plotNodes(<?= $geoJson ?>);
}

function makeDot(node) {
  const sev = node.severity || 'low';
  const col = SEVCO[sev] || '#3d5570';
  const r   = {critical:9,high:7,medium:5,low:4}[sev] || 4;
  return L.divIcon({
    className:'',iconSize:[r*2,r*2],iconAnchor:[r,r],
    html:`<div style="width:${r*2}px;height:${r*2}px;border-radius:50%;background:${col};
      opacity:.88;border:1.5px solid rgba(255,255,255,.22);
      box-shadow:0 0 ${r+2}px ${col}66;cursor:pointer;transition:transform .1s"
      onmouseover="this.style.transform='scale(1.4)'" onmouseout="this.style.transform='scale(1)'"></div>`,
  });
}

function plotNodes(nodes) {
  mLayer.clearLayers();
  allMarkers = {};
  let n = 0;
  nodes.forEach(node => {
    if (!node.coords||!activeD.has(node.domain)) return;
    try {
      const lat = node.coords[0], lon = node.coords[1];
      if (!lat && !lon) return;
      const m = L.marker([lat,lon],{icon:makeDot(node)})
        .on('click',()=>showMapPop(node));
      m.addTo(mLayer);
      allMarkers[node.id] = {m, node};
      n++;
    } catch(e){}
  });
  log(`Map: ${n} points`,'info');
}

function showMapPop(node) {
  $('mpopt').textContent = (node.title||'').slice(0,90);
  $('mpkv').innerHTML = `
    <span class="mpk">Domain</span><span class="mpv"><span class="dtag d-${node.domain||''}">${node.domain||''}</span></span>
    <span class="mpk">Severity</span><span class="mpv sv ${node.severity||'low'}">${(node.severity||'low').toUpperCase()}</span>
    <span class="mpk">Source</span><span class="mpv">${e(node.source||'')}</span>
    <span class="mpk">Time</span><span class="mpv">${e((node.timestamp||'').slice(0,16))}</span>
    ${node.location?`<span class="mpk">Location</span><span class="mpv">${e(node.location)}</span>`:''}
    ${node.coords?`<span class="mpk">Coords</span><span class="mpv">${node.coords[0]}°, ${node.coords[1]}°</span>`:''}
    ${node.url?`<span class="mpk">Link</span><span class="mpv"><a href="${e(node.url)}" target="_blank" style="color:var(--b)">↗ Source</a></span>`:''}
  `;
  $('mpop').classList.add('on');
}

function togF(d) {
  const btn = document.querySelector(`.mfbtn[data-d="${d}"]`);
  if (activeD.has(d)) { activeD.delete(d); btn.classList.remove('on'); }
  else                { activeD.add(d);    btn.classList.add('on');    }
  refilter();
}

function togAll() {
  const doms = <?= json_encode(array_keys(DOMAINS)) ?>;
  const allOn = activeD.size === doms.length;
  if (allOn) { activeD.clear(); document.querySelectorAll('.mfbtn[data-d]').forEach(b=>b.classList.remove('on')); }
  else        { doms.forEach(d=>activeD.add(d)); document.querySelectorAll('.mfbtn[data-d]').forEach(b=>b.classList.add('on')); }
  refilter();
}

function refilter() {
  Object.values(allMarkers).forEach(({m,node})=>{
    if (activeD.has(node.domain)) mLayer.addLayer(m);
    else mLayer.removeLayer(m);
  });
}

function refreshMap() {
  fetch('?api=mapdata').then(r=>r.json()).then(res=>{
    if(res.ok){ plotNodes(res.data); $('sv-g').innerHTML=res.data.length+'<em>P</em>'; }
  }).catch(()=>{});
}


function selD(d) {
  curD = d;
  document.querySelectorAll('.dtab').forEach(el=>el.classList.remove('on'));
  $('dt-'+d)?.classList.add('on');
  loadFeed(d);
}

function loadFeed(d) {
  $('ftitle').textContent = d[0].toUpperCase()+d.slice(1);
  $('elist').innerHTML=`<div style="padding:20px;text-align:center;color:var(--t2);font-family:var(--mono);font-size:9px">Loading…</div>`;
  fetch(`?api=domain&d=${d}`).then(r=>r.json()).then(res=>{
    if(!res.ok) throw new Error(res.error);
    $('fcnt').textContent = res.data.length;
    renderFeed(res.data.slice(0,25));
    log(`Domain ${d}: ${res.data.length} nodes`,'ok');
  }).catch(err=>log(err.message,'err'));
}

function renderFeed(nodes) {
  if(!nodes.length){
    $('elist').innerHTML=`<div style="padding:24px;text-align:center;color:var(--t2);font-family:var(--mono);font-size:9px">No nodes in this domain yet</div>`;
    return;
  }
  $('elist').innerHTML = nodes.map(n=>`
    <div class="ei c-${n.severity||'low'}" onclick="showNode('${e(n.id||'')}')">
      <span class="eiic">${n.icon||'PIN POINT'}</span>
      <div>
        <div class="eiti">${e(String(n.title||'').slice(0,115))}</div>
        <div class="eime">
          <span class="dtag d-${n.domain||''}">${n.domain||''}</span>
          <span class="eisrc">${e(n.source||'')}</span>
          <span class="eits">${e((n.timestamp||'').slice(0,16))}</span>
        </div>
      </div>
      <span class="sv ${n.severity||'low'}">${(n.severity||'LOW').toUpperCase()}</span>
    </div>`).join('');
}


function doSync() {
  const btn=$('btn-sync');
  btn.disabled=true; btn.textContent='⟳ SYNCING…';
  log('Pulling live sources — 8 feeds + GDELT + USGS + NVD…','info');
  fetch('?api=ingest').then(r=>r.json()).then(res=>{
    if(!res.ok) throw new Error(res.error);
    const d=res.data;
    log(`Sync done: +${d.nodes_added} nodes · ${d.edges} edges · total ${d.total_nodes}N`,'ok');
    log(d.sources.join(' · '),'info');
    refreshStats();
    loadFeed(curD);
    refreshMap();
  }).catch(err=>log(err.message,'err'))
  .finally(()=>{ btn.disabled=false; btn.textContent='SYNC'; });
}


function doBrief() {
  const btn=$('btn-brief'), box=$('bbox');
  btn.disabled=true; btn.textContent='⚡ THINKING…';
  box.classList.add('load'); box.textContent='';
  log('Requesting AI World Brief (Groq 70b)…','info');
  fetch('?api=brief').then(r=>r.json()).then(res=>{
    if(!res.ok) throw new Error(res.error);
    box.classList.remove('load');
    typeText(box, res.data.brief||'(empty)');
    $('bmodel').textContent = res.data.model||'';
    log(`Brief: ${res.data.tokens} chars`,'ok');
  }).catch(err=>{ box.classList.remove('load'); box.textContent='Error: '+err.message; log(err.message,'err'); })
  .finally(()=>{ btn.disabled=false; btn.textContent='BRIEF'; });
}

//QUERY
function doQuery() {
  const q=$('qin').value.trim(); if(!q) return;
  const box=$('qbox');
  box.classList.add('load'); box.textContent='';
  log(`Query: "${q.slice(0,60)}"…`,'info');
  fetch(`?api=query&q=${encodeURIComponent(q)}`).then(r=>r.json()).then(res=>{
    if(!res.ok) throw new Error(res.error);
    box.classList.remove('load');
    typeText(box, res.data.answer||'(empty)');
    log(`Answer: ${res.data.tokens}c · ${res.data.scanned} nodes scanned`,'ok');
  }).catch(err=>{ box.classList.remove('load'); box.textContent='Error: '+err.message; log(err.message,'err'); });
}
function qq(q){ $('qin').value=q; doQuery(); }

//CII
function doCII() {
  log('Computing Country Instability Index…','info');
  fetch('?api=cii').then(r=>r.json()).then(res=>{
    if(!res.ok) throw new Error(res.error);
    renderCII(res.data);
    log(`CII: ${res.data.length} countries scored`,'ok');
  }).catch(err=>log(err.message,'err'));
}

function renderCII(sc) {
  sc.sort((a,b)=>b.score-a.score);
  $('ciibody').innerHTML = sc.map(s=>{
    const p=Math.min(100,s.score);
    const t=p>=70?3:p>=50?2:p>=30?1:0;
    const tr = s.trend>0?`<span style="color:var(--r)">+${s.trend}</span>`
             : s.trend<0?`<span style="color:var(--g)">${s.trend}</span>`
             : `<span style="color:var(--t2)">—</span>`;
    const sigs=(s.signals||[]).map(g=>`<span class="tag" style="font-size:7px">${e(g)}</span>`).join(' ');
    return `<tr>
      <td class="cn">${e(s.country)}</td>
      <td><div class="bw"><div class="bar"><div class="barf b${t}" style="width:${p}%"></div></div><span class="bv bv${t}">${s.score}</span></div></td>
      <td>${tr}</td><td>${sigs}</td></tr>`;
  }).join('');
}

function showNode(nid) {
  if(!nid) return;
  fetch('?api=graph').then(r=>r.json()).then(res=>{
    const n=Object.values(res.data?.nodes||{}).find(x=>x.id===nid);
    if(!n) return;
    $('npt').textContent=(n.title||n.id||'Node').slice(0,80);
    $('npb').innerHTML=`
      <div class="nkv">
        <span class="nk">ID</span><span class="nv">${e(n.id||'')}</span>
        <span class="nk">Domain</span><span class="nv"><span class="dtag d-${n.domain||''}">${n.domain||''}</span></span>
        <span class="nk">Severity</span><span class="nv sv ${n.severity||'low'}">${(n.severity||'low').toUpperCase()}</span>
        <span class="nk">Source</span><span class="nv">${e(n.source||'')}</span>
        <span class="nk">Timestamp</span><span class="nv">${e((n.timestamp||'').slice(0,16))}</span>
        ${n.location?`<span class="nk">Location</span><span class="nv">${e(n.location)}</span>`:''}
        ${n.coords?`<span class="nk">Coords</span><span class="nv">${n.coords[0]}°, ${n.coords[1]}°</span>`:''}
        ${n.confidence!=null?`<span class="nk">Confidence</span><span class="nv">${n.confidence}</span>`:''}
        ${n.url?`<span class="nk">Source URL</span><span class="nv"><a href="${e(n.url)}" target="_blank" style="color:var(--b)">↗ Open</a></span>`:''}
        ${n.fatalities!=null?`<span class="nk">Fatalities</span><span class="nv sv critical">${n.fatalities}</span>`:''}
        ${n.cve_id?`<span class="nk">CVE</span><span class="nv sv critical">${e(n.cve_id)}</span>`:''}
      </div>`;
    $('np').classList.add('show');
    $('np').scrollIntoView({behavior:'smooth',block:'nearest'});
    if(n.coords && map) map.setView([n.coords[0],n.coords[1]],5,{animate:true});
  });
}


function refreshStats() {
  fetch('?api=status').then(r=>r.json()).then(res=>{
    if(!res.ok) return;
    const d=res.data;
    $('sv-n').innerHTML=d.nodes+'<em>N</em>';
    $('sv-e').innerHTML=d.edges+'<em>E</em>';
    $('sv-c').innerHTML=d.cache+'<em>C</em>';
    $('tn').textContent=d.nodes; $('te').textContent=d.edges; $('tg').textContent=d.geo||'';
    if(d.updated) $('upd-v').textContent=new Date(d.updated).toLocaleString();
    if(d.by_domain) Object.entries(d.by_domain).forEach(([dk,cnt])=>{
      const el=$('dn-'+dk); if(el) el.textContent=cnt;
    });
  }).catch(()=>{});
}

function doStatus() {
  fetch('?api=status').then(r=>r.json()).then(res=>{
    if(!res.ok) return;
    const d=res.data;
    log(`${d.nodes}N/${d.edges}E · geo:${d.geo} · cache:${d.cache} · upd:${d.updated||'never'}`,'info');
  });
}

function typeText(el, text, spd=9) {
  el.textContent=''; el.innerHTML='<span class="cur"></span>';
  const cur=el.querySelector('.cur'); let i=0;
  const iv=setInterval(()=>{
    if(i>=text.length){ clearInterval(iv); cur.remove(); return; }
    el.insertBefore(document.createTextNode(text.slice(i,i+5)),cur); i+=5;
  },spd);
}

function log(msg, type='info') {
  const ts=new Date().toTimeString().slice(0,8);
  const el=document.createElement('div'); el.className='ll';
  el.innerHTML=`<span class="lts">${ts}</span><span class="lm ${type}">${e(msg)}</span>`;
  $('log').prepend(el);
  while($('log').children.length>80) $('log').lastChild.remove();
}
function clrLog(){ $('log').innerHTML=''; log('Log cleared','info'); }

function e(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

addEventListener('DOMContentLoaded',()=>{
  initMap();
  log('Global Ontology Engine online','ok');
  <?php if ($nc > 0): ?>
  log('Graph: <?= $nc ?>N / <?= $ec ?>E / <?= count($geoNodes) ?> geo-points','ok');
  <?php endif; ?>
  setInterval(refreshStats, 60000);
});
//khatam main page
</script>
</body>
</html>
