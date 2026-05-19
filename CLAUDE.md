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
2. `searchAllCollections()` – Qdrant `/points/search` per collection, sedan hybrid-ranking (se nedan)
3. `buildContext()` – sortera per prio→score, formatera till LLM-kontext med tier-etikett
4. `streamChatResponse()` / `generateChatResponse()` – OpenAI Chat (stream: true i stream.php)

### Priority-aware hybrid-ranking

Varje Qdrant-punkt bär `priority` (1/2/3) som stämplas av crawlern utifrån redaktionellt Google-ark. Lokala källor (prio 1+2) ska dominera AI-sammanfattningen utan att helt utesluta nationella (prio 3). Tre mekanismer i `searchAllCollections`:

1. **Score-boost** (`PRIORITY_BOOST = [1=>1.20, 2=>1.10, 3=>1.00]`) — effective_score = cosine × boost. Ger lokalt fördel utan att svälta nationella hög-relevans-träffar (78 × 1.00 slår fortfarande 62 × 1.20).
2. **Reserverade platser** (`RESERVED_LOCAL_SLOTS = 3`, `MIN_LOCAL_SCORE = 0.40`) — de tre översta platserna i slutresultatet reserveras för bästa prio 1/2-träffar förutsatt att deras råa score ≥ 0.40. Garanterar att lokala alltid finns i AI-kontexten.
3. **Kontext-ordning** (`buildContext` sorterar per prio→score) — LLM grundar svaret starkare i tidigt presenterade dokument, så lokala riktlinjer kommer först i prompten.

Konstanterna ligger på filtopp i `stream.php` (top-level `const` är **inte** hissad i PHP — måste deklareras före main flow) och i `helper.php` som klasskonstanter.

Systemprompten innehåller: *"Utgå i första hand från lokala riktlinjer och lokala källor. Använd nationella källor som komplement för nationella regler eller när lokala källor inte täcker frågan."*

`priority`-fältet defaultar till 2 (neutral) om saknas i payload — så gamla punkter utan stämpel beter sig som tidigare.

### Qdrant payload-fält

Alla collections har:
- `text` – dokumentets innehåll
- `page_title` – titel (används i källlistan)
- `source_url` – URL (görs klickbar i källlistan)
- `priority` – heltal 1/2/3 från crawler-config (saknas → behandlas som 2)
- `chunk_index` – används som dedup-nyckel i ranking-logiken

Source-svaret till klienten inkluderar `priority` och `priority_label` ("lokal riktlinje" / "lokal källa" / "nationell källa") så frontend kan visa märkning om man vill.

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
