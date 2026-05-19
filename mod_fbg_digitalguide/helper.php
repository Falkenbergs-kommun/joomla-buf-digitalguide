<?php
/**
 * Helper class for FBG Digital Guide Module
 *
 * Implements a RAG (Retrieval-Augmented Generation) pipeline:
 * 1. Converts user query to embedding via OpenAI
 * 2. Searches Qdrant vector collections for relevant documents
 * 3. Builds context from retrieved documents
 * 4. Generates a summarized answer via OpenAI Chat
 *
 * AJAX endpoint:
 *   index.php?option=com_ajax&module=fbg_digitalguide&method=search&format=json
 *
 * @package    FBG Digital Guide
 * @subpackage mod_fbg_digitalguide
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Registry\Registry;

class ModFbgDigitalguideHelper
{
	// Priority-aware ranking constants — speglar stream.php.
	// Lokala källor (prio 1+2) får mjuk boost; nationella (prio 3) får ingen straff.
	const PRIORITY_BOOST = [1 => 1.20, 2 => 1.10, 3 => 1.00];
	const PRIORITY_DEFAULT = 2;
	const RESERVED_LOCAL_SLOTS = 3;
	const MIN_LOCAL_SCORE = 0.40;

	private static function extractPriority(array $payload): int
	{
		$p = $payload['priority'] ?? self::PRIORITY_DEFAULT;
		return is_numeric($p) ? (int)$p : self::PRIORITY_DEFAULT;
	}

	private static function priorityLabel(int $prio): string
	{
		return [1 => 'lokal riktlinje', 2 => 'lokal källa', 3 => 'nationell källa'][$prio] ?? 'källa';
	}

	/**
	 * Search Qdrant collections and generate a RAG answer.
	 * AJAX endpoint: &method=search
	 *
	 * @return array Result with answer and sources, or error
	 */
	public static function searchAjax()
	{
		try {
			$input    = Factory::getApplication()->input;
			$question = trim($input->getString('question', ''));

			if (empty($question)) {
				throw new \Exception('Frågan eller sökordet kan inte vara tomt.');
			}

			// Load module configuration from database
			$params = self::getModuleParams();

			$qdrantUrl      = rtrim($params->get('qdrant_url', 'https://qdrant.utvecklingfalkenberg.se'), '/');
			$qdrantApiKey   = $params->get('qdrant_api_key', '');
			$openaiApiKey   = $params->get('openai_api_key', '');
			$embeddingModel = $params->get('embedding_model', 'text-embedding-3-large');
			$chatModel      = $params->get('chat_model', 'gpt-5.2-chat-latest');
			$topK           = max(1, (int)$params->get('top_k', 5));
			$collectionsStr = $params->get('collections', 'fokus-ai,unikum-guider,digitalguidenstdochresurser,utskrift,digitalguiden,skollagen,skolverket');
			$collections    = array_filter(array_map('trim', explode(',', $collectionsStr)));

			if (empty($qdrantApiKey) || empty($openaiApiKey)) {
				throw new \Exception('API-nycklar saknas. Konfigurera modulen i Joomla-administratörspanelen.');
			}

			if (empty($collections)) {
				throw new \Exception('Inga Qdrant-collections konfigurerade.');
			}

			// Step 1: Generate vector embedding for the user's query
			$embedding = self::generateEmbedding($question, $openaiApiKey, $embeddingModel);

			// Step 2: Search across all configured collections
			$results = self::searchAllCollections($embedding, $collections, $topK, $qdrantUrl, $qdrantApiKey);

			// Step 3: Build prompt context from retrieved documents
			$context = self::buildContext($results);

			// Step 4: Generate answer via OpenAI Chat
			$chatResponse = self::generateChatResponse($question, $context, $openaiApiKey, $chatModel, $collections);

			// Format sources for the response (max 5 shown)
			$sources = array_map(function ($result) {
				$prio = $result['priority'] ?? self::PRIORITY_DEFAULT;
				return [
					'collection'       => $result['collection'],
					'collection_label' => self::getCollectionLabel($result['collection']),
					'score'            => round($result['score'] * 100, 1),
					'priority'         => $prio,
					'priority_label'   => self::priorityLabel($prio),
					'snippet'          => self::extractSnippet($result['payload']),
					'title'            => self::extractTitle($result['payload']),
					'url'              => self::extractUrl($result['payload']),
				];
			}, array_slice($results, 0, 5));

			return [
				'success'  => true,
				'question' => $question,
				'answer'   => $chatResponse['answer'],
				'sources'  => $sources,
			];

		} catch (\Exception $e) {
			error_log('ModFbgDigitalguide searchAjax error: ' . $e->getMessage());

			return [
				'success' => false,
				'error'   => $e->getMessage(),
			];
		}
	}

	/**
	 * Load module params from database (needed in AJAX context where $params is not available).
	 */
	private static function getModuleParams()
	{
		$db    = Factory::getDbo();
		$query = $db->getQuery(true)
			->select($db->quoteName('params'))
			->from($db->quoteName('#__modules'))
			->where($db->quoteName('module') . ' = ' . $db->quote('mod_fbg_digitalguide'))
			->where($db->quoteName('published') . ' = 1');

		$db->setQuery($query);
		$paramsJson = $db->loadResult();

		return new Registry($paramsJson ?: '{}');
	}

	/**
	 * Make an HTTP request using cURL.
	 */
	private static function makeRequest($url, $method = 'GET', $data = null, $headers = [])
	{
		$ch = curl_init($url);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 90);

		if ($method === 'POST') {
			curl_setopt($ch, CURLOPT_POST, true);
			if ($data !== null) {
				curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
			}
		}

		if (!empty($headers)) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		}

		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error    = curl_error($ch);

		curl_close($ch);

		if ($error) {
			throw new \Exception("cURL-fel: $error");
		}

		return ['code' => $httpCode, 'body' => $response];
	}

	/**
	 * Generate a vector embedding for the given text via OpenAI Embeddings API.
	 */
	private static function generateEmbedding($text, $apiKey, $model)
	{
		$response = self::makeRequest(
			'https://api.openai.com/v1/embeddings',
			'POST',
			[
				'model'           => $model,
				'input'           => $text,
				'encoding_format' => 'float',
			],
			[
				'Authorization: Bearer ' . $apiKey,
				'Content-Type: application/json',
			]
		);

		if ($response['code'] !== 200) {
			throw new \Exception('OpenAI Embedding-fel (HTTP ' . $response['code'] . '): ' . $response['body']);
		}

		$data = json_decode($response['body'], true);

		if (!isset($data['data'][0]['embedding'])) {
			throw new \Exception('Ogiltigt svar från OpenAI Embeddings API.');
		}

		return $data['data'][0]['embedding'];
	}

	/**
	 * Search a single Qdrant collection with a vector embedding.
	 */
	private static function searchQdrant($collection, $embedding, $limit, $qdrantUrl, $apiKey)
	{
		$url = $qdrantUrl . '/collections/' . $collection . '/points/search';

		$response = self::makeRequest(
			$url,
			'POST',
			[
				'vector'       => $embedding,
				'limit'        => $limit,
				'with_payload' => true,
				'with_vector'  => false,
			],
			[
				'api-key: ' . $apiKey,
				'Content-Type: application/json',
			]
		);

		if ($response['code'] !== 200) {
			throw new \Exception("Qdrant-fel för collection \"$collection\" (HTTP " . $response['code'] . '): ' . $response['body']);
		}

		$data = json_decode($response['body'], true);

		return $data['result'] ?? [];
	}

	/**
	 * Search across all configured collections and return merged results sorted by score.
	 */
	private static function searchAllCollections($embedding, $collections, $topK, $qdrantUrl, $qdrantApiKey)
	{
		$allResults = [];

		foreach ($collections as $collection) {
			try {
				$results = self::searchQdrant($collection, $embedding, $topK, $qdrantUrl, $qdrantApiKey);

				foreach ($results as $result) {
					$prio  = self::extractPriority($result['payload']);
					$boost = self::PRIORITY_BOOST[$prio] ?? 1.0;
					$allResults[] = [
						'collection'      => $collection,
						'score'           => $result['score'],
						'effective_score' => $result['score'] * $boost,
						'priority'        => $prio,
						'payload'         => $result['payload'],
					];
				}
			} catch (\Exception $e) {
				// Log and continue – don't fail if one collection is unavailable
				error_log('ModFbgDigitalguide: Fel vid sökning i collection "' . $collection . '": ' . $e->getMessage());
			}
		}

		// Garanterad inkludering: reservera platser för bästa lokala träffarna
		// (prio 1+2) som klarar minimitröskeln för relevans.
		$resultKey = fn ($r) => ($r['payload']['source_url'] ?? '') . '#' . ($r['payload']['chunk_index'] ?? '');

		$local = array_filter(
			$allResults,
			fn ($r) => $r['priority'] <= 2 && $r['score'] >= self::MIN_LOCAL_SCORE
		);
		usort($local, fn ($a, $b) => $b['effective_score'] <=> $a['effective_score']);
		$reserved     = array_slice($local, 0, self::RESERVED_LOCAL_SLOTS);
		$reservedKeys = array_flip(array_map($resultKey, $reserved));

		$rest = array_filter($allResults, fn ($r) => !isset($reservedKeys[$resultKey($r)]));
		usort($rest, fn ($a, $b) => $b['effective_score'] <=> $a['effective_score']);

		return array_slice(array_merge($reserved, $rest), 0, $topK * 2);
	}

	/**
	 * Build context string from retrieved documents for the LLM prompt.
	 */
	private static function buildContext($results)
	{
		if (empty($results)) {
			return 'Ingen relevant information hittades i kunskapsbasen.';
		}

		// Sortera per prio (lägst nummer först), sedan score — så lokala riktlinjer
		// kommer först i kontexten och LLM grundar sammanfattningen starkare i dem.
		$ordered = $results;
		usort($ordered, function ($a, $b) {
			$pa = $a['priority'] ?? self::PRIORITY_DEFAULT;
			$pb = $b['priority'] ?? self::PRIORITY_DEFAULT;
			if ($pa !== $pb) return $pa <=> $pb;
			return $b['score'] <=> $a['score'];
		});

		$context = "Relevant information från kunskapsbasen:\n\n";

		foreach ($ordered as $index => $result) {
			$label    = self::getCollectionLabel($result['collection']);
			$prio     = $result['priority'] ?? self::PRIORITY_DEFAULT;
			$tier     = self::priorityLabel($prio);
			$relevans = round($result['score'] * 100, 1);

			$context .= '--- Dokument ' . ($index + 1) . " (Källa: $label, $tier, relevans: $relevans%) ---\n";
			$context .= self::extractFullText($result['payload']);
			$context .= "\n\n";
		}

		return $context;
	}

	/**
	 * Generate a chat completion using OpenAI with retrieved context.
	 */
	private static function generateChatResponse($question, $context, $apiKey, $model, $collections)
	{
		$systemPrompt = self::buildSystemPrompt($collections);

		$messages = [
			['role' => 'system', 'content' => $systemPrompt],
			['role' => 'user', 'content' => "Kontext:\n$context\n\nAnvändarens inmatning: $question"],
		];

		$requestBody = [
			'model'                => $model,
			'messages'             => $messages,
			'max_completion_tokens' => 1500,
		];

		// Some newer models don't accept a custom temperature
		if (!str_contains($model, 'gpt-5.2-chat-latest')) {
			$requestBody['temperature'] = 0.7;
		}

		$response = self::makeRequest(
			'https://api.openai.com/v1/chat/completions',
			'POST',
			$requestBody,
			[
				'Authorization: Bearer ' . $apiKey,
				'Content-Type: application/json',
			]
		);

		if ($response['code'] !== 200) {
			throw new \Exception('OpenAI Chat-fel (HTTP ' . $response['code'] . '): ' . $response['body']);
		}

		$data = json_decode($response['body'], true);

		if (!isset($data['choices'][0]['message']['content'])) {
			throw new \Exception('Ogiltigt svar från OpenAI Chat API.');
		}

		return [
			'answer' => $data['choices'][0]['message']['content'],
			'usage'  => $data['usage'] ?? null,
		];
	}

	/**
	 * Build the system prompt, dynamically including configured collection names.
	 */
	private static function buildSystemPrompt($collections)
	{
		$labels     = array_map([self::class, 'getCollectionLabel'], $collections);
		$sourceList = implode(', ', $labels);

		return "Du är en intern kunskapsassistent som svarar på frågor och sökningar baserat på information från följande kunskapsbaser: $sourceList.\n"
			. "Använd den tillhandahållna kontexten för att ge korrekta och relevanta svar.\n"
			. "Om inmatningen är ett enstaka nyckelord eller en kort fras (sökning): presentera de mest relevanta dokumenten i en kortfattad lista med vad de handlar om.\n"
			. "Om inmatningen är en fråga: ge ett sammanhängande, informativt svar baserat på kontexten.\n"
			. "Om svaret inte finns i kontexten: säg det tydligt istället för att gissa.\n"
			. "Källorna är markerade med tier (lokal riktlinje / lokal källa / nationell källa). "
			. "Utgå i första hand från lokala riktlinjer och lokala källor. "
			. "Använd nationella källor (t.ex. Skolverket, Skollagen, SPSM) som komplement när frågan rör nationella regler "
			. "eller när lokala källor inte täcker frågan.\n"
			. "Svara alltid på svenska. Var koncis men informativ.\n"
			. "Ange gärna vilken källa ($sourceList) informationen kommer från.\n"
			. "Avsluta aldrig med att erbjuda ytterligare hjälp eller ställa följdfrågor – detta är en enkel fråga-svar-tjänst utan möjlighet till uppföljning.";
	}

	/**
	 * Return a human-readable label for a collection name.
	 */
	public static function getCollectionLabel($collection)
	{
		$labels = [
			'fokus-ai'                     => 'Fokus AI',
			'unikum-guider'                => 'Unikum Guider',
			'digitalguidenstdochresurser'  => 'Digitalguiden stöd och resurser',
			'utskrift'                     => 'Utskrifter',
			'digitalguiden'                => 'Digitalguiden',
			'skollagen'                    => 'Skollagen',
			'skolverket'                   => 'Skolverket',
			'vardhandboken'                => 'Vårdhandboken',
			'evolution'                    => 'Evolution',
		];

		return $labels[$collection] ?? ucwords(str_replace('-', ' ', $collection));
	}

	/**
	 * Extract full text content from a Qdrant payload.
	 */
	private static function extractFullText($payload)
	{
		if (isset($payload['text'])) {
			return $payload['text'];
		}

		if (isset($payload['content'])) {
			return $payload['content'];
		}

		if (isset($payload['page_content'])) {
			return $payload['page_content'];
		}

		// Fallback: serialize entire payload
		return json_encode($payload, JSON_UNESCAPED_UNICODE);
	}

	/**
	 * Extract a short text snippet from a payload for display in the sources list.
	 */
	private static function extractSnippet($payload, $maxLength = 180)
	{
		$text = self::extractFullText($payload);
		$text = trim(preg_replace('/\s+/', ' ', $text));

		if (mb_strlen($text) > $maxLength) {
			$text = mb_substr($text, 0, $maxLength) . '…';
		}

		return $text;
	}

	/**
	 * Try to extract a document title from the payload.
	 */
	private static function extractTitle($payload)
	{
		foreach (['page_title', 'title', 'name', 'filename'] as $field) {
			if (!empty($payload[$field]) && is_string($payload[$field])) {
				return $payload[$field];
			}
		}

		return '';
	}

	private static function extractUrl($payload)
	{
		foreach (['source_url', 'url', 'link', 'source'] as $field) {
			if (!empty($payload[$field]) && is_string($payload[$field])) {
				$url = $payload[$field];
				// Percent-encode non-ASCII chars so FILTER_VALIDATE_URL accepts IRIs
				$encoded = preg_replace_callback('/[^\x20-\x7E]/', function ($m) {
					return rawurlencode($m[0]);
				}, $url);
				if (filter_var($encoded, FILTER_VALIDATE_URL)) {
					return $url;
				}
			}
		}

		return '';
	}
}
