import os, sys, json, hashlib, time, datetime, re, math, traceback
import urllib.request, urllib.parse, urllib.error
import xml.etree.ElementTree as ET
from pathlib import Path
from typing import Optional


ROOT       = Path(__file__).parent.parent
GRAPH_FILE = ROOT / 'data' / 'graph.json'
CACHE_DIR  = ROOT / 'cache'
CACHE_DIR.mkdir(exist_ok=True)
(ROOT / 'data').mkdir(exist_ok=True)

GROQ_KEY     = os.environ.get('GROQ_API_KEY', '')
GROQ_URL     = 'https://api.groq.com/openai/v1/chat/completions'
FAST_MODELS  = ['llama-3.1-8b-instant', 'gemma2-9b-it', 'gemma-7b-it']
REASON_MODEL = 'llama-3.3-70b-versatile'
HTTP_TIMEOUT = 14
CACHE_TTL    = 900
GRAPH_MAX    = 8000
_calls       = 0
_midx        = 0

def log(msg, level='INFO'):
    print(f"[{datetime.datetime.now().strftime('%H:%M:%S')}][{level}] {msg}", flush=True)

def fetch(url: str, ttl: int = CACHE_TTL, headers: dict = None) -> Optional[str]:
    cf = CACHE_DIR / (hashlib.md5(url.encode()).hexdigest()[:16] + '.hc')
    if cf.exists() and (time.time() - cf.stat().st_mtime) < ttl:
        return cf.read_text(encoding='utf-8', errors='replace')
    try:
        req = urllib.request.Request(url)
        req.add_header('User-Agent', 'GOE/2.1 research')
        req.add_header('Accept', 'application/json, application/xml, text/xml, */*')
        if headers:
            for k, v in headers.items(): req.add_header(k, v)
        import ssl
        ctx = ssl.create_default_context()
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
        with urllib.request.urlopen(req, timeout=HTTP_TIMEOUT, context=ctx) as r:
            body = r.read().decode('utf-8', errors='replace')
        cf.write_text(body, encoding='utf-8')
        return body
    except Exception as ex:
        log(f"fetch({url[:55]}): {ex}", 'WARN')
        return None

def groq(prompt: str, system: str = '', model: str = '',
         json_mode: bool = False, tokens: int = 300, cache: bool = True) -> Optional[str]:
    global _calls, _midx
    if not GROQ_KEY: return None
    if not model: model = FAST_MODELS[_midx % len(FAST_MODELS)]
    cf = CACHE_DIR / (hashlib.md5(f"{model}{prompt}".encode()).hexdigest()[:16] + '.gc')
    if cache and cf.exists() and (time.time() - cf.stat().st_mtime) < CACHE_TTL:
        return json.loads(cf.read_text()).get('v')
    msgs = []
    if system: msgs.append({'role': 'system', 'content': system})
    msgs.append({'role': 'user', 'content': prompt})
    body = {'model': model, 'messages': msgs, 'max_tokens': tokens, 'temperature': 0.0}
    if json_mode: body['response_format'] = {'type': 'json_object'}
    try:
        data = json.dumps(body).encode()
        req  = urllib.request.Request(GROQ_URL, data=data)
        req.add_header('Content-Type', 'application/json')
        req.add_header('Authorization', f'Bearer {GROQ_KEY}')
        with urllib.request.urlopen(req, timeout=20) as r:
            res = json.loads(r.read())
        content = res['choices'][0]['message']['content']
        _calls += 1; _midx += 1
        if cache: cf.write_text(json.dumps({'v': content, 't': time.time()}))
        return content
    except Exception as ex:
        log(f"Groq({model}): {ex}", 'WARN')
        return None

#keyword classifier
DOMAIN_KW = {
    'defense':     ['military','army','navy','missile','weapon','troops','nato','nuclear','combat','bomb','drone','warship','airstrike'],
    'economics':   ['gdp','market','inflation','trade','sanction','currency','oil price','stock','bank','imf','debt','tariff','recession'],
    'climate':     ['earthquake','flood','hurricane','typhoon','wildfire','volcano','tsunami','drought','storm','disaster','magnitude'],
    'cyber':       ['hack','cyberattack','malware','ransomware','breach','vulnerability','ddos','phishing','data leak','espionage','cve','exploit'],
    'society':     ['election','protest','human rights','refugee','migration','pandemic','famine','poverty','coup','riot'],
    'technology':  ['artificial intelligence','semiconductor','quantum','satellite launch','chip','nvidia','openai','robot','5g'],
}
SEV_KW = {
    'critical': ['war','nuclear','invasion','assassination','coup','bombing','warhead','terror attack'],
    'high':     ['military','airstrike','missile','sanction','earthquake','threat','clash','explosion'],
    'medium':   ['tensions','dispute','incident','warning','election','unrest','protest','flood'],
}

def kw_classify(text: str):
    t = text.lower()
    domain = 'geopolitics'
    for d, kws in DOMAIN_KW.items():
        if any(kw in t for kw in kws): domain = d; break
    sev = 'low'
    for s, kws in SEV_KW.items():
        if any(kw in t for kw in kws): sev = s; break
    return domain, sev

def classify(title: str, desc: str = '', lang: str = 'en') -> dict:
    domain, sev = kw_classify(title + ' ' + desc[:200])
    if lang != 'en' or (domain == 'geopolitics' and sev == 'low'):
        r = groq(
            f'Classify:\nTitle: {title[:280]}\nDesc: {desc[:200]}\n'
            f'Return JSON: {{"domain":"geopolitics|defense|economics|climate|cyber|society|technology","severity":"critical|high|medium|low","location":"country/city or empty","confidence":0.0-1.0}}',
            system='JSON classifier. Return ONLY valid JSON.', json_mode=True, tokens=120
        )
        if r:
            try:
                d = json.loads(r)
                return {'domain': d.get('domain', domain), 'severity': d.get('severity', sev),
                        'location': d.get('location', ''), 'confidence': d.get('confidence', 0.7)}
            except: pass
    return {'domain': domain, 'severity': sev, 'location': '', 'confidence': 0.65}

ICONS = {'geopolitics':'🌐','defense':'🛡️','economics':'📊','climate':'🌿','cyber':'⚡','society':'🏛️','technology':'🔬'}

#Geo lookup
GEO = {
    'Afghanistan':[33.9,67.7],'Algeria':[28.0,2.6],'Argentina':[-38.4,-63.6],'Armenia':[40.1,45.0],
    'Australia':[-25.3,133.8],'Austria':[47.5,14.5],'Azerbaijan':[40.1,47.6],'Bangladesh':[23.7,90.4],
    'Belarus':[53.7,27.9],'Belgium':[50.5,4.5],'Brazil':[-14.2,-51.9],'Cambodia':[12.6,104.9],
    'Canada':[56.1,-106.3],'China':[35.9,104.2],'Colombia':[4.6,-74.1],'Cuba':[21.5,-79.8],
    'Egypt':[26.8,30.8],'Ethiopia':[9.1,40.5],'France':[46.2,2.2],'Germany':[51.2,10.5],
    'Ghana':[7.9,-1.0],'Greece':[39.1,21.8],'Haiti':[18.9,-72.3],'India':[20.6,79.0],
    'Indonesia':[-0.8,113.9],'Iran':[32.4,53.7],'Iraq':[33.2,43.7],'Ireland':[53.4,-8.2],
    'Israel':[31.0,34.9],'Italy':[41.9,12.6],'Japan':[36.2,138.3],'Jordan':[30.6,36.2],
    'Kazakhstan':[48.0,66.9],'Kenya':[-0.0,37.9],'Lebanon':[33.9,35.5],'Libya':[26.3,17.2],
    'Malaysia':[4.2,108.0],'Mali':[17.6,-3.9],'Mexico':[23.6,-102.6],'Morocco':[31.8,-7.1],
    'Myanmar':[17.0,96.1],'Nepal':[28.4,84.1],'Netherlands':[52.1,5.3],'Nigeria':[9.1,8.7],
    'North Korea':[40.3,127.5],'Norway':[60.5,8.5],'Pakistan':[30.4,69.3],'Palestine':[31.9,35.2],
    'Peru':[-9.2,-75.0],'Philippines':[12.9,122.0],'Poland':[51.9,19.1],'Qatar':[25.4,51.2],
    'Romania':[45.9,24.9],'Russia':[61.5,105.3],'Saudi Arabia':[24.2,45.1],'Serbia':[44.0,21.0],
    'Somalia':[5.2,46.2],'South Africa':[-30.6,22.9],'South Korea':[35.9,127.8],
    'South Sudan':[7.9,29.7],'Spain':[40.5,-3.7],'Sri Lanka':[7.9,80.7],'Sudan':[12.9,30.2],
    'Sweden':[60.1,18.6],'Syria':[34.8,38.9],'Taiwan':[23.6,120.9],'Thailand':[15.9,100.9],
    'Turkey':[38.9,35.2],'Uganda':[1.4,32.3],'UK':[55.4,-3.4],'Ukraine':[48.4,31.2],
    'USA':[37.1,-95.7],'Venezuela':[6.4,-66.6],'Vietnam':[14.1,108.3],'Yemen':[15.6,48.5],
    'Zimbabwe':[-19.0,29.2],'Congo':[0.2,15.8],'Libya':[26.3,17.2],'Tunisia':[33.9,9.5],
}

import random
def geo_lookup(text: str):
    t = text.lower()
    for country, c in GEO.items():
        if country.lower() in t:
            return [round(c[0] + random.uniform(-0.4,0.4),4),
                    round(c[1] + random.uniform(-0.4,0.4),4)]
    return None

def nid(s: str) -> str:
    return hashlib.md5(s.lower().strip().encode()).hexdigest()[:12]

def ts() -> str:
    return datetime.datetime.utcnow().strftime('%Y-%m-%d %H:%M')



def src_rss() -> list:
    feeds = [
        ('https://feeds.reuters.com/reuters/worldNews',              'Reuters',    'en'),
        ('https://feeds.bbci.co.uk/news/world/rss.xml',             'BBC World',  'en'),
        ('https://rss.dw.com/rss/en-world',                         'DW',         'en'),
        ('https://www.aljazeera.com/xml/rss/all.xml',               'Al Jazeera', 'en'),
        ('https://rss.nytimes.com/services/xml/rss/nyt/World.xml',  'NYT',        'en'),
        ('https://www.defenseone.com/rss/all/',                     'DefenseOne', 'en'),
        ('https://www.thedrive.com/the-war-zone/rss',               'WarZone',    'en'),
        ('https://www.thehindu.com/news/international/?service=rss','The Hindu',  'en'),
        ('https://feeds.feedburner.com/TheHackersNews',             'HackerNews', 'en'),
        ('https://www.ft.com/rss/home',                             'FT',         'en'),
        # Hindi
        ('https://www.aajtak.in/rssfeeds/1206578.xml',              'Aaj Tak',    'hi'),
        # Arabic
        ('https://www.aljazeera.net/aljazeerarss/a2/a2.xml',        'AJ Arabic',  'ar'),
    ]
    events = []
    for url, src, lang in feeds[:8]:
        raw = fetch(url, 600)
        if not raw: continue
        try:
            ET.register_namespace('', 'http://www.w3.org/2005/Atom')
            root = ET.fromstring(raw.encode('utf-8', 'replace'))
            items = root.findall('.//item') or root.findall('.//{http://www.w3.org/2005/Atom}entry')
            for it in items[:18]:
                def tx(tag): return (it.findtext(tag) or it.findtext(f'{{http://www.w3.org/2005/Atom}}{tag}') or '').strip()
                title = re.sub('<[^>]+>','', tx('title'))
                desc  = re.sub('<[^>]+>','', tx('description') or tx('summary'))[:350]
                link  = tx('link')
                pub   = tx('pubDate') or tx('published')
                if len(title) < 8: continue
                c = classify(title, desc, lang)
                events.append({
                    'id': nid(title), 'title': title, 'desc': desc, 'url': link,
                    'domain': c['domain'], 'severity': c['severity'],
                    'location': c['location'], 'confidence': c['confidence'],
                    'source': src, 'lang': lang, 'tier': 1,
                    'timestamp': ts(), 'icon': ICONS.get(c['domain'],'📌'),
                    'type': 'news', 'coords': geo_lookup(title+' '+desc+' '+c['location']),
                })
        except Exception as ex:
            log(f"RSS {src}: {ex}", 'WARN')
    log(f"RSS: {len(events)} events")
    return events

def src_gdelt() -> list:
    queries = [
        ('military conflict war',            'defense'),
        ('geopolitical crisis diplomacy',    'geopolitics'),
        ('economic sanctions trade',         'economics'),
        ('cyber attack espionage breach',    'cyber'),
        ('natural disaster flood wildfire',  'climate'),
        ('India Pakistan China border',      'geopolitics'),
        ('Russia Ukraine war',               'defense'),
        ('nuclear weapons missile launch',   'defense'),
        ('artificial intelligence chip',     'technology'),
        ('protest election coup society',    'society'),
    ]
    events = []
    for q, dom in queries[:6]:
        url = ('https://api.gdeltproject.org/api/v2/doc/doc?query=' + urllib.parse.quote(q)
               + '&mode=artlist&maxrecords=12&format=json&timespan=1440')
        raw = fetch(url, 900)
        if not raw: continue
        try:
            for a in json.loads(raw).get('articles', []):
                title = a.get('title','')
                if not title: continue
                tone  = float(a.get('tone', 0))
                sev   = 'high' if tone < -5 else ('medium' if tone < -2 else 'low')
                loc   = a.get('sourcecountry','')
                events.append({
                    'id': nid(title), 'title': title, 'url': a.get('url',''),
                    'domain': dom, 'severity': sev, 'tone': round(tone,2),
                    'location': loc, 'confidence': 0.75, 'source': 'GDELT',
                    'lang': a.get('language','en'), 'tier': 1,
                    'timestamp': ts(), 'icon': ICONS.get(dom,'📌'),
                    'type': 'gdelt', 'coords': geo_lookup(title+' '+loc),
                })
        except Exception as ex:
            log(f"GDELT: {ex}", 'WARN')
    log(f"GDELT: {len(events)} events")
    return events

def src_usgs() -> list:
    raw = fetch('https://earthquake.usgs.gov/fdsnws/event/1/query?format=geojson&minmagnitude=4.0&limit=30&orderby=time', 300)
    if not raw: return []
    events = []
    try:
        for f in json.loads(raw).get('features', []):
            p    = f.get('properties', {})
            mag  = round(float(p.get('mag', 0)), 1)
            plc  = p.get('place', 'Unknown')
            c    = f.get('geometry', {}).get('coordinates', [0,0,0])
            sev  = 'critical' if mag>=7 else ('high' if mag>=6 else ('medium' if mag>=5 else 'low'))
            events.append({
                'id': f'eq_{f.get("id", nid(str(mag)+plc))}',
                'title': f'M{mag} earthquake — {plc}',
                'domain': 'climate', 'severity': sev, 'magnitude': mag,
                'location': plc, 'depth_km': round(float(c[2] if len(c)>2 else 0),1),
                'coords': [round(float(c[1]),4), round(float(c[0]),4)],
                'source': 'USGS', 'tier': 1, 'lang': 'en',
                'timestamp': ts(), 'icon': '🌋', 'type': 'earthquake', 'confidence': 1.0,
            })
    except Exception as ex:
        log(f"USGS: {ex}", 'WARN')
    log(f"USGS: {len(events)} earthquakes")
    return events

def src_eonet() -> list:
    raw = fetch('https://eonet.gsfc.nasa.gov/api/v3/events?status=open&limit=20', 600)
    if not raw: return []
    MAP = {'Wildfires':['🔥','high'],'Severe Storms':['🌪️','medium'],'Volcanoes':['🌋','high'],'Floods':['🌊','medium']}
    events = []
    try:
        for ev in json.loads(raw).get('events', []):
            cat  = ev.get('categories',[{}])[0].get('title','Other')
            geo  = ev.get('geometry',[{}])[0]
            c    = geo.get('coordinates',[0,0])
            icon, sev = MAP.get(cat, ['⚠️','low'])
            events.append({
                'id': f'eonet_{ev.get("id", nid(ev.get("title","")))}',
                'title': ev.get('title','Natural event'), 'domain': 'climate',
                'severity': sev, 'category': cat, 'location': cat,
                'coords': [round(float(c[1]),4), round(float(c[0]),4)] if len(c)>=2 else None,
                'source': 'NASA EONET', 'tier': 1, 'lang': 'en',
                'timestamp': ts(), 'icon': icon, 'type': 'natural_event', 'confidence': 0.9,
            })
    except Exception as ex:
        log(f"EONET: {ex}", 'WARN')
    log(f"EONET: {len(events)} events")
    return events

def src_nvd() -> list:
    """NIST NVD — Critical CVEs"""
    raw = fetch('https://services.nvd.nist.gov/rest/json/cves/2.0?cvssV3Severity=CRITICAL&resultsPerPage=12', 1800)
    if not raw: return []
    events = []
    try:
        for item in json.loads(raw).get('vulnerabilities', []):
            cve  = item.get('cve', {})
            cid  = cve.get('id', '')
            desc = next((d['value'] for d in cve.get('descriptions',[]) if d.get('lang')=='en'), '')
            if not cid: continue
            events.append({
                'id': nid(cid), 'title': f'CRITICAL CVE: {cid} — {desc[:95]}',
                'desc': desc[:300], 'domain': 'cyber', 'severity': 'critical',
                'cve_id': cid, 'location': '', 'coords': None,
                'source': 'NIST NVD', 'tier': 1, 'lang': 'en',
                'timestamp': ts(), 'icon': '⚡', 'type': 'cve', 'confidence': 1.0,
            })
    except Exception as ex:
        log(f"NVD: {ex}", 'WARN')
    log(f"NVD: {len(events)} CVEs")
    return events

def src_worldbank() -> list:
    isos = ['IN','CN','RU','US','PK','IR','UA','DE','FR','TR','SA','BR','NG','EG','PH']
    events = []
    for iso in isos[:10]:
        url = f'https://api.worldbank.org/v2/country/{iso}/indicator/FP.CPI.TOTL.ZG?format=json&mrv=1&per_page=1'
        raw = fetch(url, 86400)
        if not raw: continue
        try:
            data = json.loads(raw)
            row  = (data[1] or [None])[0] if len(data) > 1 else None
            if not row or row.get('value') is None: continue
            val   = round(float(row['value']), 2)
            cname = row.get('country',{}).get('value', iso)
            sev   = 'high' if val > 20 else ('medium' if val > 10 else 'low')
            events.append({
                'id': nid(iso+'cpi'), 'title': f'{cname}: Inflation CPI = {val}%',
                'domain': 'economics', 'severity': sev, 'value': val,
                'country_iso': iso, 'location': cname,
                'coords': geo_lookup(cname),
                'source': 'World Bank', 'tier': 1, 'lang': 'en',
                'timestamp': ts(), 'icon': '📊', 'type': 'indicator', 'confidence': 1.0,
            })
        except: pass
    log(f"World Bank: {len(events)} indicators")
    return events

def src_celestrak() -> list:
    groups = [('stations','🛸','Space Station'),('military','🛰️','Military Sat')]
    events = []
    for grp, icon, label in groups:
        raw = fetch(f'https://celestrak.org/NORAD/elements/gp.php?GROUP={grp}&FORMAT=json', 3600)
        if not raw: continue
        try:
            for s in json.loads(raw)[:4]:
                name  = s.get('OBJECT_NAME','Unknown')
                norad = str(s.get('NORAD_CAT_ID',''))
                events.append({
                    'id': f'sat_{norad}', 'title': f'{label}: {name} (NORAD #{norad})',
                    'domain': 'defense', 'severity': 'low', 'norad_id': norad,
                    'location': '', 'coords': None, 'source': 'CelesTrak',
                    'tier': 1, 'lang': 'en', 'timestamp': ts(),
                    'icon': icon, 'type': 'satellite', 'confidence': 1.0,
                })
        except Exception as ex:
            log(f"CelesTrak {grp}: {ex}", 'WARN')
    log(f"CelesTrak: {len(events)} satellites")
    return events

def src_polymarket() -> list:
    raw = fetch('https://clob.polymarket.com/markets?tag_slug=geopolitics&limit=10&order=volume&ascending=false', 300)
    if not raw: return []
    events = []
    try:
        for m in json.loads(raw).get('data', []):
            q    = m.get('question','')
            if not q: continue
            prob = round(float((m.get('outcomePrices') or ['0.5'])[0]) * 100, 1)
            sev  = 'high' if prob > 75 else ('medium' if prob > 50 else 'low')
            events.append({
                'id': f'poly_{nid(q)}', 'title': f'Prediction: {q} ({prob}% YES)',
                'domain': 'geopolitics', 'severity': sev, 'probability': prob,
                'location': '', 'coords': geo_lookup(q),
                'source': 'Polymarket', 'tier': 2, 'lang': 'en',
                'timestamp': ts(), 'icon': '🔮', 'type': 'prediction', 'confidence': 0.7,
            })
    except Exception as ex:
        log(f"Polymarket: {ex}", 'WARN')
    log(f"Polymarket: {len(events)} markets")
    return events

def src_acled() -> list:
    key   = os.environ.get('ACLED_KEY','')
    email = os.environ.get('ACLED_EMAIL','')
    if not key or not email: return []
    start = (datetime.datetime.utcnow()-datetime.timedelta(days=3)).strftime('%Y-%m-%d')
    end   = datetime.datetime.utcnow().strftime('%Y-%m-%d')
    url   = (f'https://api.acleddata.com/acled/read?key={urllib.parse.quote(key)}'
             f'&email={urllib.parse.quote(email)}&limit=20'
             f'&fields=event_date|event_type|country|location|fatalities|actor1|actor2|latitude|longitude'
             f'&event_date_where=BETWEEN&event_date={start}|{end}')
    raw = fetch(url, 1800)
    if not raw: return []
    events = []
    try:
        for ev in json.loads(raw).get('data', []):
            fatal = int(ev.get('fatalities', 0))
            sev   = 'critical' if fatal>50 else ('high' if fatal>10 else ('medium' if fatal>0 else 'low'))
            title = f"{ev.get('event_type','Event')} — {ev.get('location','')}, {ev.get('country','')}"
            lat   = float(ev.get('latitude', 0) or 0)
            lon   = float(ev.get('longitude', 0) or 0)
            events.append({
                'id': nid(title+str(ev.get('event_date',''))),
                'title': title, 'fatalities': fatal,
                'domain': 'defense', 'severity': sev,
                'location': f"{ev.get('location','')}, {ev.get('country','')}",
                'coords': [lat,lon] if (lat or lon) else None,
                'source': 'ACLED', 'tier': 1, 'lang': 'en',
                'timestamp': ts(), 'icon': '⚔️', 'type': 'conflict', 'confidence': 0.95,
            })
    except Exception as ex:
        log(f"ACLED: {ex}", 'WARN')
    log(f"ACLED: {len(events)} conflicts")
    return events

#graph engine

def load_graph():
    if GRAPH_FILE.exists():
        try:
            g = json.loads(GRAPH_FILE.read_text())
            if isinstance(g, dict) and 'nodes' in g: return g
        except: pass
    return {'nodes': {}, 'edges': [], 'meta': {'version':'2.1','created':ts(),'updated':None}}

def save_graph(g):
    g['meta']['updated']    = ts()
    g['meta']['node_count'] = len(g['nodes'])
    g['meta']['edge_count'] = len(g['edges'])
    if len(g['nodes']) > GRAPH_MAX:
        nodes = sorted(g['nodes'].values(), key=lambda x: x.get('timestamp',''), reverse=True)
        keep  = {n['id']: n for n in nodes[:GRAPH_MAX-500]}
        keep_ids = set(keep.keys())
        g['nodes'] = keep
        g['edges'] = [e for e in g['edges'] if e['from'] in keep_ids and e['to'] in keep_ids]
    GRAPH_FILE.write_text(json.dumps(g, ensure_ascii=False, separators=(',',':')))

def add_node(g, node) -> bool:
    nid_ = node.get('id', nid(node.get('title','')))
    node['id'] = nid_
    if nid_ in g['nodes']:
        g['nodes'][nid_]['mentions'] = g['nodes'][nid_].get('mentions',1) + 1
        return False
    g['nodes'][nid_] = {**{'mentions':1,'added':ts()}, **node}
    return True

def auto_link(g, nid_):
    n = g['nodes'].get(nid_)
    if not n: return
    nwords = set(re.findall(r'[a-z]{4,}', n.get('title','').lower()))
    ndomain= n.get('domain','')
    nts    = n.get('timestamp','')[:10]
    linked = 0
    for oid, x in list(g['nodes'].items()):
        if oid == nid_ or linked >= 25: break
        xwords = set(re.findall(r'[a-z]{4,}', x.get('title','').lower()))
        if len(nwords & xwords) >= 2:
            exists = any(e['from']==nid_ and e['to']==oid for e in g['edges'])
            if not exists:
                g['edges'].append({'from':nid_,'to':oid,'type':'mentions','w':0.6,'ts':ts()})
            linked += 1



def main():
    t0 = time.time()
    log(f"GOE v2.1 Ingest | Python {sys.version.split()[0]} | Groq: {'YES' if GROQ_KEY else 'NO (set GROQ_API_KEY)'}")
    g = load_graph()
    added = 0

    pipeline = [
        ('rss',        src_rss),
        ('gdelt',      src_gdelt),
        ('usgs',       src_usgs),
        ('eonet',      src_eonet),
        ('nvd',        src_nvd),
        ('worldbank',  src_worldbank),
        ('celestrak',  src_celestrak),
        ('polymarket', src_polymarket),
        ('acled',      src_acled),
    ]

    results = {}
    for name, fn in pipeline:
        t1 = time.time()
        try:
            events = fn()
            n = 0
            for ev in events:
                if add_node(g, ev):
                    auto_link(g, ev['id'])
                    n += 1; added += 1
            results[name] = n
            log(f"  [{name}] +{n} ({len(events)} fetched) {round(time.time()-t1,1)}s")
        except Exception as ex:
            log(f"  [{name}] ERROR: {ex}", 'WARN')
            traceback.print_exc()
            results[name] = 0

    save_graph(g)
    elapsed = round(time.time()-t0, 1)
    summary = {
        'nodes_added': added, 'edges': len(g['edges']),
        'total_nodes': len(g['nodes']), 'sources': results,
        'groq_calls': _calls, 'elapsed': elapsed, 'ts': ts(),
    }
    (ROOT/'data'/'last_ingest.json').write_text(json.dumps(summary, indent=2))
    log(f"Done: +{added} nodes | {len(g['edges'])} edges | {_calls} Groq calls | {elapsed}s")
    return summary

if __name__ == '__main__':
    main()
