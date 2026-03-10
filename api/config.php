<?php
if (!defined('GOE_VER')) define('GOE_VER', '2.1'); 

define('GROQ_KEY',   getenv('GROQ_API_KEY') ?: 'YOUR_GROQ_API_KEY_HERE');
define('GROQ_URL',   'https://api.groq.com/openai/v1/chat/completions');

//models
define('MODEL_FAST',   'llama-3.1-8b-instant');
define('MODEL_REASON', 'llama-3.3-70b-versatile');
define('FAST_POOL',    ['llama-3.1-8b-instant', 'gemma2-9b-it', 'gemma-7b-it']);

define('ROOT',          dirname(__DIR__));
define('GRAPH_FILE',    ROOT . '/data/graph.json');
define('INGEST_FILE',   ROOT . '/data/last_ingest.json');
define('CACHE_DIR',     ROOT . '/cache');
define('PY_INGEST',     ROOT . '/python/ingest.py');

//limit
define('CACHE_TTL',     900);  // 15 min
define('HTTP_TIMEOUT',  10);    // sec/req
define('GRAPH_MAX',     8000); // max nodes before prune

define('DOMAINS', [
    'geopolitics' => ['label' => 'Geopolitics', 'icon' => '🌐', 'color' => '#f5a623'],
    'defense'     => ['label' => 'Defense',     'icon' => '🛡️',  'color' => '#ff4757'],
    'economics'   => ['label' => 'Economics',   'icon' => '📊',  'color' => '#00ff88'],
    'climate'     => ['label' => 'Climate',     'icon' => '🌿',  'color' => '#26c6da'],
    'cyber'       => ['label' => 'Cyber',       'icon' => '⚡',  'color' => '#b39ddb'],
    'society'     => ['label' => 'Society',     'icon' => '🏛️',  'color' => '#4fc3f7'],
    'technology'  => ['label' => 'Technology',  'icon' => '🔬',  'color' => '#69f0ae'],
]);

//CII baseline fragility
define('CII_BASELINE', [
    'Russia'=>72,'China'=>44,'Iran'=>76,'North Korea'=>88,'Ukraine'=>82,
    'Israel'=>66,'Pakistan'=>62,'India'=>34,'USA'=>28,'Turkey'=>52,
    'Saudi Arabia'=>42,'Syria'=>92,'Myanmar'=>82,'Sudan'=>87,'Ethiopia'=>74,
    'Venezuela'=>72,'Taiwan'=>56,'Japan'=>14,'South Korea'=>28,'France'=>24,
    'Afghanistan'=>90,'Yemen'=>88,'Libya'=>80,'Somalia'=>85,'Iraq'=>70,
    'Germany'=>20,'UK'=>22,'Brazil'=>48,'Nigeria'=>60,'Indonesia'=>35,
]);

//Keyword classifiers
define('DOM_KW', [
    'defense'    => ['military','army','navy','missile','weapon','troops','nato','nuclear','combat','bomb','drone','warship','airstrike','artillery','submarine','regiment'],
    'economics'  => ['gdp','market','inflation','trade','sanction','currency','oil price','stock','bank','imf','debt','export','import','tariff','recession','fiscal','interest rate','budget'],
    'climate'    => ['earthquake','flood','hurricane','typhoon','wildfire','volcano','tsunami','drought','storm','climate','disaster','magnitude','cyclone','heatwave','seismic'],
    'cyber'      => ['hack','cyberattack','malware','ransomware','breach','vulnerability','ddos','phishing','data leak','espionage','apt','exploit','botnet','zero-day','cve','intrusion'],
    'society'    => ['election','protest','human rights','refugee','migration','pandemic','famine','poverty','coup','riot','strike','demonstration','uprising','violence'],
    'technology' => ['ai','artificial intelligence','semiconductor','quantum','satellite launch','tech','innovation','5g','chip','nvidia','openai','robot','space mission'],
]);
define('SEV_KW', [
    'critical' => ['war','nuclear','invasion','assassination','coup','mass casualty','bombing','warhead','genocide','terror attack'],
    'high'     => ['military','airstrike','missile','sanction','earthquake','surge','threat','clash','conflict','arrested','explosion'],
    'medium'   => ['tensions','dispute','incident','warning','election','unrest','alert','exercise','protest','fire','flood'],
]);
