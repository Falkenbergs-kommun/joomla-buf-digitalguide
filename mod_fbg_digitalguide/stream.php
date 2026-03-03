<?php
/**
 * FBG Digital Guide – SSE Streaming endpoint
 *
 * Körs direkt av webbläsaren (inte via com_ajax).
 * Implementerar RAG-pipelinen och strömmar svaret som Server-Sent Events:
 *
 *   event: sources  – källdokument (skickas före svaret)
 *   event: chunk    – textbit från OpenAI
 *   event: error    – felmeddelande
 *
 * Konfiguration läses från Joomlas databas via configuration.php.
 *
 * @package FBG Digital Guide
 */

// ── Output-buffering och SSE-headers ─────────────────────────────────────
while (ob_get_level()) {
    ob_end_clean();
}
@ini_set('implicit_flush', 1);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store');
header('X-Accel-Buffering: no');   // Stäng av nginx-buffring
header('Connection: keep-alive');

// ── Validering ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendEvent('error', ['message' => 'Endast POST-begäran tillåten.']);
    exit;
}

$question = trim($_POST['question'] ?? '');
if (empty($question)) {
    sendEvent('error', ['message' => 'Frågan kan inte vara tom.']);
    exit;
}

// ── Ladda konfiguration från Joomla ──────────────────────────────────────
// DOCUMENT_ROOT pekar på Joomla-roten oavsett symlink-placering
$joomlaRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
$configFile = $joomlaRoot . '/configuration.php';

if (!file_exists($configFile)) {
    sendEvent('error', ['message' => 'Joomla-konfiguration saknas.']);
    exit;
}

try {
    require_once $configFile;
    $jConfig = new JConfig();

    $dsn = 'mysql:host=' . $jConfig->host . ';dbname=' . $jConfig->db . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $jConfig->user, $jConfig->password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $prefix = $jConfig->dbprefix;
    $stmt   = $pdo->prepare(
        "SELECT params FROM `{$prefix}modules` WHERE module = 'mod_fbg_digitalguide' AND published = 1 LIMIT 1"
    );
    $stmt->execute();
    $params = json_decode($stmt->fetchColumn() ?: '{}', true);

} catch (\Exception $e) {
    sendEvent('error', ['message' => 'Konfigurationsfel: ' . $e->getMessage()]);
    exit;
}

$qdrantUrl   = rtrim($params['qdrant_url']      ?? 'https://qdrant.utvecklingfalkenberg.se', '/');
$qdrantKey   = $params['qdrant_api_key']        ?? '';
$openaiKey   = $params['openai_api_key']        ?? '';
$embModel    = $params['embedding_model']       ?? 'text-embedding-3-large';
$chatModel   = $params['chat_model']            ?? 'gpt-5.2-chat-latest';
$topK        = max(1, (int)($params['top_k']    ?? 5));
$collectStr  = $params['collections']           ?? 'buf-digitalisering,fokus-ai,unikum-guider';
$collections = array_filter(array_map('trim', explode(',', $collectStr)));

if (empty($openaiKey) || empty($qdrantKey)) {
    sendEvent('error', ['message' => 'API-nycklar saknas. Konfigurera modulen i Joomla-administrationspanelen.']);
    exit;
}

// ── RAG-pipeline ──────────────────────────────────────────────────────────
try {
    // Steg 1: Skapa embedding för frågan
    $embedding = generateEmbedding($question, $openaiKey, $embModel);

    // Steg 2: Sök i alla Qdrant-collections
    $results = searchAllCollections($embedding, $collections, $topK, $qdrantUrl, $qdrantKey);

    // Steg 3: Skicka källorna till klienten direkt (innan LLM svarar)
    sendEvent('sources', ['sources' => formatSources(array_slice($results, 0, 5))]);

    // Steg 4: Bygg kontext och strömma OpenAI-svar
    $context = buildContext($results);
    streamChatResponse($question, $context, $openaiKey, $chatModel, $collections);

} catch (\Exception $e) {
    sendEvent('error', ['message' => $e->getMessage()]);
    error_log('FBG Digital Guide stream.php error: ' . $e->getMessage());
}

// ════════════════════════════════════════════════════════════════════════════
// SSE-helper
// ════════════════════════════════════════════════════════════════════════════

function sendEvent(string $event, array $data): void
{
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level()) {
        ob_flush();
    }
    flush();
}

// ════════════════════════════════════════════════════════════════════════════
// OpenAI – Embedding
// ════════════════════════════════════════════════════════════════════════════

function generateEmbedding(string $text, string $apiKey, string $model): array
{
    $resp = curlPost(
        'https://api.openai.com/v1/embeddings',
        ['model' => $model, 'input' => $text, 'encoding_format' => 'float'],
        ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json']
    );

    if ($resp['code'] !== 200) {
        throw new \Exception('OpenAI Embedding-fel (HTTP ' . $resp['code'] . ')');
    }

    $data = json_decode($resp['body'], true);
    if (!isset($data['data'][0]['embedding'])) {
        throw new \Exception('Ogiltigt svar från OpenAI Embeddings API.');
    }

    return $data['data'][0]['embedding'];
}

// ════════════════════════════════════════════════════════════════════════════
// Qdrant – Vektorsökning
// ════════════════════════════════════════════════════════════════════════════

function searchQdrant(string $collection, array $embedding, int $limit, string $url, string $apiKey): array
{
    $resp = curlPost(
        "{$url}/collections/{$collection}/points/search",
        ['vector' => $embedding, 'limit' => $limit, 'with_payload' => true, 'with_vector' => false],
        ['api-key: ' . $apiKey, 'Content-Type: application/json']
    );

    if ($resp['code'] !== 200) {
        throw new \Exception("Qdrant-fel för \"{$collection}\" (HTTP {$resp['code']})");
    }

    $data = json_decode($resp['body'], true);
    return $data['result'] ?? [];
}

function searchAllCollections(array $embedding, array $collections, int $topK, string $qdrantUrl, string $qdrantKey): array
{
    $all = [];

    foreach ($collections as $col) {
        try {
            foreach (searchQdrant($col, $embedding, $topK, $qdrantUrl, $qdrantKey) as $r) {
                $all[] = ['collection' => $col, 'score' => $r['score'], 'payload' => $r['payload']];
            }
        } catch (\Exception $e) {
            error_log("FBG Digital Guide: Fel vid sökning i \"{$col}\": " . $e->getMessage());
        }
    }

    usort($all, fn ($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($all, 0, $topK * 2);
}

// ════════════════════════════════════════════════════════════════════════════
// Kontext och källformatering
// ════════════════════════════════════════════════════════════════════════════

function buildContext(array $results): string
{
    if (empty($results)) {
        return 'Ingen relevant information hittades i kunskapsbasen.';
    }

    $ctx = "Relevant information från kunskapsbasen:\n\n";
    foreach ($results as $i => $r) {
        $label    = getCollectionLabel($r['collection']);
        $relevans = round($r['score'] * 100, 1);
        $ctx .= "--- Dokument " . ($i + 1) . " (Källa: {$label}, relevans: {$relevans}%) ---\n";
        $ctx .= extractText($r['payload']) . "\n\n";
    }

    return $ctx;
}

function formatSources(array $results): array
{
    return array_map(function ($r) {
        $p = $r['payload'];
        return [
            'collection'       => $r['collection'],
            'collection_label' => getCollectionLabel($r['collection']),
            'score'            => round($r['score'] * 100, 1),
            'title'            => extractTitle($p),
            'url'              => extractUrl($p),
            'snippet'          => extractSnippet($p),
        ];
    }, $results);
}

function extractText(array $p): string
{
    return $p['text'] ?? $p['content'] ?? $p['page_content']
        ?? json_encode($p, JSON_UNESCAPED_UNICODE);
}

function extractTitle(array $p): string
{
    foreach (['page_title', 'title', 'name', 'filename'] as $f) {
        if (!empty($p[$f]) && is_string($p[$f])) {
            return $p[$f];
        }
    }
    return '';
}

function extractUrl(array $p): string
{
    foreach (['source_url', 'url', 'link', 'source'] as $f) {
        if (!empty($p[$f]) && is_string($p[$f]) && filter_var($p[$f], FILTER_VALIDATE_URL)) {
            return $p[$f];
        }
    }
    return '';
}

function extractSnippet(array $p, int $max = 200): string
{
    $text = trim(preg_replace('/\s+/', ' ', extractText($p)));
    return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
}

function getCollectionLabel(string $col): string
{
    return [
        'buf-digitalisering' => 'BUF Digitalisering',
        'fokus-ai'           => 'Fokus AI',
        'unikum-guider'      => 'Unikum Guider',
    ][$col] ?? ucwords(str_replace('-', ' ', $col));
}

// ════════════════════════════════════════════════════════════════════════════
// OpenAI – Streaming Chat
// ════════════════════════════════════════════════════════════════════════════

function streamChatResponse(string $question, string $context, string $apiKey, string $model, array $collections): void
{
    $labels     = array_map('getCollectionLabel', $collections);
    $sourceList = implode(', ', $labels);

    $systemPrompt =
        "Du är en hjälpsam AI-assistent som svarar på frågor och sökningar baserat på information från: {$sourceList}.\n"
        . "Använd den tillhandahållna kontexten för att ge korrekta och relevanta svar.\n"
        . "Om inmatningen är ett sökord: lista de mest relevanta dokumenten kortfattat.\n"
        . "Om inmatningen är en fråga: ge ett sammanhängande svar baserat på kontexten.\n"
        . "Om svaret inte finns i kontexten, säg det tydligt.\n"
        . "Svara alltid på svenska. Var koncis men informativ.\n"
        . "Ange gärna vilken källa informationen kommer från.";

    $bodyArr = [
        'model'                 => $model,
        'messages'              => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => "Kontext:\n{$context}\n\nAnvändarens inmatning: {$question}"],
        ],
        'stream'                => true,
        'max_completion_tokens' => 1500,
    ];

    if (!str_contains($model, 'gpt-5.2-chat-latest')) {
        $bodyArr['temperature'] = 0.7;
    }

    $ch = curl_init('https://api.openai.com/v1/chat/completions');

    // Buffer för ofullständiga SSE-rader från OpenAI
    $lineBuffer = '';

    curl_setopt_array($ch, [
        CURLOPT_POST          => true,
        CURLOPT_POSTFIELDS    => json_encode($bodyArr),
        CURLOPT_HTTPHEADER    => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_WRITEFUNCTION  => function ($ch, $data) use (&$lineBuffer) {
            $lineBuffer .= $data;

            // Bearbeta kompletta rader
            while (($pos = strpos($lineBuffer, "\n")) !== false) {
                $line       = substr($lineBuffer, 0, $pos);
                $lineBuffer = substr($lineBuffer, $pos + 1);
                $line       = rtrim($line, "\r");

                if (!str_starts_with($line, 'data: ')) {
                    continue;
                }

                $json = substr($line, 6);
                if ($json === '[DONE]') {
                    continue;
                }

                $chunk   = json_decode($json, true);
                $content = $chunk['choices'][0]['delta']['content'] ?? '';

                if ($content !== '') {
                    sendEvent('chunk', ['text' => $content]);
                }
            }

            return strlen($data);
        },
    ]);

    curl_exec($ch);

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        throw new \Exception("Streaming cURL-fel: {$curlErr}");
    }
    if ($httpCode !== 200) {
        throw new \Exception("OpenAI Streaming-fel (HTTP {$httpCode})");
    }
}

// ════════════════════════════════════════════════════════════════════════════
// cURL-hjälp för icke-streaming anrop
// ════════════════════════════════════════════════════════════════════════════

function curlPost(string $url, array $data, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 60,
    ]);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        throw new \Exception("cURL-fel: {$err}");
    }

    return ['code' => $code, 'body' => $body];
}
