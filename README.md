# Digitalguiden – Joomla-modul för RAG-sökning

En Joomla 5-modul som låter användaren söka i en Qdrant-vektordatabas och få
AI-genererade svar via OpenAI. Modulen förstår om användaren ställer en fråga
eller söker på nyckelord och ger ett sammanfattande svar med länkade källdokument.

## Funktioner

- **Sök eller fråga** – heuristik avgör om inmatningen är ett sökord eller en fråga
- **RAG-pipeline** – Embedding → Qdrant-vektorsökning → kontextbygge → OpenAI Chat
- **Streaming** – svaret växer fram token för token, precis som i en chatbot
- **Länkade källor** – källdokument visas med titel och direktlänk
- **WCAG-anpassad** – `role="search"`, synlig label, 44 px träffyta, fokusindikatorer
- **UIkit 3 / YOOtheme Pro** – stilar som passar intranätets tema

## Teknikstack

| Del | Teknologi |
|---|---|
| Modul | Joomla 5, PHP 8.x |
| Vektorsökning | Qdrant (`https://qdrant.utvecklingfalkenberg.se`) |
| Embeddings | OpenAI `text-embedding-3-large` |
| Svarsgenerering | OpenAI `gpt-5.2-chat-latest` |
| AJAX (fallback) | Joomla `com_ajax` |
| Streaming | Server-Sent Events (SSE) via `stream.php` |
| Frontend | UIkit 3, vanilla JS med jQuery |

## Filstruktur

```
buf-digitalguide/
└── mod_fbg_digitalguide/
    ├── mod_fbg_digitalguide.xml    ← Manifest – parametrar och metadata
    ├── mod_fbg_digitalguide.php    ← Entry point – laddar JS/CSS, skickar config
    ├── helper.php                  ← AJAX-handler via com_ajax (searchAjax)
    ├── stream.php                  ← SSE-endpoint för streaming-svar
    ├── tmpl/
    │   └── default.php             ← HTML-template med UIkit 3-komponenter
    └── assets/
        ├── js/digitalguide.js      ← fetch()-streaming, markdown-rendering, UX
        └── css/digitalguide.css    ← Minimal CSS anpassad för UIkit 3
```

## Installation

### Via filsystem (development)

1. Klona repot:
   ```bash
   git clone https://github.com/Falkenbergs-kommun/joomla-digitalguide.git \
     /home/httpd/fbg-intranet/joomlaextensions/buf-digitalguide
   ```

2. Skapa symlink till Joomlas modulkatalog:
   ```bash
   ln -s /home/httpd/fbg-intranet/joomlaextensions/buf-digitalguide/mod_fbg_digitalguide \
         /home/httpd/fbg-intranet/dev-intra.falkenberg.se/modules/mod_fbg_digitalguide
   ```

3. Installera i Joomla admin: **Extensions → Manage → Discover** och publicera modulen.

### Via Joomla Extensions Manager

1. Gå till **Extensions → Manage → Install → Install from Folder**
2. Ange sökväg: `.../buf-digitalguide/mod_fbg_digitalguide`
3. Klicka Install

## Konfiguration

Modulen konfigureras i Joomla-adminpanelen under **Extensions → Modules → Digitalguiden**.

### Grundinställningar

| Parameter | Beskrivning | Standard |
|---|---|---|
| Rubrik | Text som visas ovanför sökfältet | `Digitalguiden` |
| Placeholder-text | Text i sökfältet | `Sök eller ställ en fråga...` |
| Collections | Qdrant-collections att söka i (kommaseparerat) | `buf-digitalisering,fokus-ai,unikum-guider` |
| Antal träffar per collection | Hur många dokument som hämtas per collection | `5` |
| Visa källdokument | Visar källlänkar under svaret | Ja |

### API-inställningar

| Parameter | Beskrivning |
|---|---|
| Qdrant-server URL | URL till Qdrant-instansen |
| Qdrant API-nyckel | API-nyckel för Qdrant |
| OpenAI API-nyckel | API-nyckel för OpenAI (klistras in i sin helhet) |
| Embedding-modell | Modell för vektorembeddings |
| Chat-modell | Modell för att generera svar |

## RAG-pipeline

```
Användaren skriver fråga/sökord
        │
        ▼
stream.php tar emot POST-request
        │
        ├─ 1. Genererar embedding via OpenAI Embeddings API
        │
        ├─ 2. Söker i alla konfigurerade Qdrant-collections parallellt
        │      Sorterar träffar efter relevanspoäng
        │
        ├─ SSE: event: sources  ──► Källdokument visas i webbläsaren
        │
        ├─ 3. Bygger kontext av de bästa träffarna
        │
        ├─ 4. Skickar kontext + fråga till OpenAI Chat (stream: true)
        │
        └─ SSE: event: chunk × N  ──► Svar växer fram i realtid
```

## Qdrant-payload-format

Modulen förväntar sig följande fält i Qdrant-payloaden:

| Fält | Innehåll |
|---|---|
| `text` / `content` / `page_content` | Dokumentets textinnehåll |
| `page_title` | Dokumentets titel (visas i källlistan) |
| `source_url` | URL till originaldokumentet (görs klickbar) |
| `site_name` | Webbplatsens namn (valfritt) |

## AJAX-endpoint (fallback)

```
GET/POST index.php?option=com_ajax&module=fbg_digitalguide&method=search&format=json
POST-parameter: question (string)
```

Svar:
```json
{
  "success": true,
  "data": {
    "success": true,
    "question": "...",
    "answer": "...",
    "sources": [
      {
        "collection": "buf-digitalisering",
        "collection_label": "BUF Digitalisering",
        "score": 87.3,
        "title": "Sidans titel",
        "url": "https://...",
        "snippet": "Kort utdrag ur dokumentet..."
      }
    ]
  }
}
```

## SSE-endpoint (streaming)

```
POST /modules/mod_fbg_digitalguide/stream.php
POST-parameter: question (string)
```

Events:
```
event: sources
data: {"sources": [...]}

event: chunk
data: {"text": "token"}

event: error
data: {"message": "Felbeskrivning"}
```

## Relaterade projekt

- [Qdrant Chat](../integrationer/qdrant-chat/) – fristående RAG-endpoint som modulen bygger vidare på
- [Telefonbok](https://github.com/Falkenbergs-kommun/joomla-telefonbok) – referensimplementation för Joomla-modulens AJAX-mönster
