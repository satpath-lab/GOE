
# GOE — Global Ontology Engine

## Quick Start

export GROQ_API_KEY=gsk_your_key_here

php -S localhost:8080



## File Structure

```
goe/
├── index.php              ← Dashboard + JSON API router
├── api/
│   ├── config.php         ← Keys, models, constants (PHP 8.5 safe)
│   ├── http.php           ← HTTP helper — stream_context
│   ├── graph.php          ← Knowledge graph engine
│   ├── groq.php           ← Groq LLM client
│   ├── sources.php        ← PHP data ingestion (8 sources)
│   └── intelligence.php   ← World Brief, NL Query, CII
├── python/
│   └── ingest.py          ← Deep Python ingestion (more sources, multilingual)
├── data/
│   ├── graph.json         ← Live knowledge graph
│   └── last_ingest.json   ← Last ingest stats
└── cache/                 ← HTTP + Groq response cache
```


## Data Sources

| Source | Domain | Notes |
|--------|--------|-------|
| Reuters / BBC / DW / Al Jazeera / NYT | All | RSS |
| DefenseOne / WarZone | Defense | RSS |
| The Hindu / ThePrint | India | RSS |
| The Hacker News | Cyber | RSS |
| GDELT DOC API | All | Real-time, global |
| USGS Earthquakes | Climate | M4.0+, real-time |
| NASA EONET | Climate | Open events |
| NIST NVD | Cyber | Critical CVEs |
| World Bank | Economics | CPI inflation |
| CelesTrak | Defense/Space | Satellite TLE |
| Polymarket | Geopolitics | Prediction markets |

---

## API Endpoints

| URL | Description |
|-----|-------------|
| `?api=status` | System stats |
| `?api=ingest` | Pull all sources now |
| `?api=brief` | AI World Brief (Groq 70b) |
| `?api=query&q=...` | NL query over graph |
| `?api=domain&d=defense` | Nodes by domain |
| `?api=mapdata` | Geo-tagged nodes only |
| `?api=cii` | Country Instability Index |
| `?api=graph` | Full graph JSON |

---

## Python Ingest (More Sources)

```bash
# Install Python (no pip packages needed — stdlib only)
GROQ_API_KEY=gsk_xxx python3 python/ingest.py
```

---

## Cron (Auto-sync every 15 min)

```bash
*/15 * * * * GROQ_API_KEY=gsk_xxx php /path/to/goe/index.php api=ingest >> /var/log/goe.log 2>&1
```
