# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Vad är detta?

Joomla 5-modul (`mod_fbg_digitalguide`) för RAG-baserad sökning och frågebesvarning på Falkenbergs kommuns intranät. Modulen heter **Digitalguiden** på sajten.

## Deployment

Modulen installeras via filsystem – ingen byggprocess krävs.

```bash
# Symlink från Joomla-installation till detta repo (redan satt)
/dev-intra.falkenberg.se/modules/mod_fbg_digitalguide
  → /joomlaextensions/buf-digitalguide/mod_fbg_digitalguide

# Ändringar slår igenom direkt. PHP-cachning kan kräva:
# Extensions → Manage → Clear Cache i Joomla-admin
```

## PHP-syntaxkontroll

```bash
php -l mod_fbg_digitalguide/stream.php
php -l mod_fbg_digitalguide/helper.php
php -l mod_fbg_digitalguide/mod_fbg_digitalguide.php
```

## Arkitektur

### Två parallella request-vägar

```
Webbläsare
  │
  ├─ fetch() POST → /modules/mod_fbg_digitalguide/stream.php
  │    SSE-streaming: event:sources → event:chunk×N
  │    Läser config från Joomla DB via $_SERVER['DOCUMENT_ROOT']/configuration.php
  │
  └─ jQuery.ajax POST → index.php?option=com_ajax&module=fbg_digitalguide&method=search&format=json
       Joomla wraps svar: {success: bool, data: {...}}
       Routas till ModFbgDigitalguideHelper::searchAjax() i helper.php
```

Streaming-vägen (stream.php) är primär och används av JS. `com_ajax`-vägen (helper.php) är fallback.

### RAG-pipeline (samma logik i båda filerna)

1. `generateEmbedding()` – OpenAI Embeddings API → float-vektor
2. `searchAllCollections()` – Qdrant `/points/search` per collection, sortera efter score
3. `buildContext()` – formatera träffar till LLM-kontext
4. `streamChatResponse()` / `generateChatResponse()` – OpenAI Chat (stream: true i stream.php)

### Qdrant payload-fält

Alla tre collections (`buf-digitalisering`, `fokus-ai`, `unikum-guider`) har:
- `text` – dokumentets innehåll
- `page_title` – titel (används i källlistan)
- `source_url` – URL (görs klickbar i källlistan)

### JavaScript-flöde (digitalguide.js)

`isQuestion()` → `showLoading()` → `fetch(streamUrl)` → läs SSE-chunks → `renderStreamingText()` (råtext under pågående stream) → `renderMarkdown()` (slutrendering när strömmen stängs).

## Konfiguration

API-nycklar lagras i Joomla-modulens params i databasen (inte i filer). `stream.php` hämtar dem via PDO mot Joomla-databasen. `helper.php` hämtar dem via `Factory::getDbo()`.

Referenskonfiguration finns i `/home/httpd/fbg-intranet/integrationer/qdrant-chat/.env`.

## Versionshantering av assets

CSS och JS laddas med `?v=N` i `mod_fbg_digitalguide.php`. Öka N vid ändringar för att tvinga webbläsaren att ladda om filen.

## Kodmönster från övriga moduler

Följ `mod_fbg_telefonbok` (telefonbok-modulen) för Joomla-specifika mönster:
- AJAX-metoder i helper-klassen slutar på `Ajax` (t.ex. `searchAjax`)
- Klassen heter `ModFbg[Modulnamn]Helper`
- JS-config skickas via `$document->addScriptDeclaration()` som ett globalt objekt
