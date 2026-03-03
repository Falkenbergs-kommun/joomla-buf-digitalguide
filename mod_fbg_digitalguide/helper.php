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
			$collectionsStr = $params->get('collections', 'buf-digitalisering,fokus-ai,unikum-guider');
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
				return [
					'collection'       => $result['collection'],
					'collection_label' => self::getCollectionLabel($result['collection']),
					'score'            => round($result['score'] * 100, 1),
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
					$allResults[] = [
						'collection' => $collection,
						'score'      => $result['score'],
						'payload'    => $result['payload'],
					];
				}
			} catch (\Exception $e) {
				// Log and continue – don't fail if one collection is unavailable
				error_log('ModFbgDigitalguide: Fel vid sökning i collection "' . $collection . '": ' . $e->getMessage());
			}
		}

		// Sort by relevance score descending
		usort($allResults, fn ($a, $b) => $b['score'] <=> $a['score']);

		// Return top results across all collections (2× topK)
		return array_slice($allResults, 0, $topK * 2);
	}

	/**
	 * Build context string from retrieved documents for the LLM prompt.
	 */
	private static function buildContext($results)
	{
		if (empty($results)) {
			return 'Ingen relevant information hittades i kunskapsbasen.';
		}

		$context = "Relevant information från kunskapsbasen:\n\n";

		foreach ($results as $index => $result) {
			$label    = self::getCollectionLabel($result['collection']);
			$relevans = round($result['score'] * 100, 1);

			$context .= '--- Dokument ' . ($index + 1) . " (Källa: $label, relevans: $relevans%) ---\n";
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

		return "Du är en hjälpsam AI-assistent som svarar på frågor och sökningar baserat på information från följande kunskapsbaser: $sourceList.\n"
			. "Använd den tillhandahållna kontexten för att ge korrekta och relevanta svar.\n"
			. "Om inmatningen är ett enstaka nyckelord eller en kort fras (sökning): presentera de mest relevanta dokumenten i en kortfattad lista med vad de handlar om.\n"
			. "Om inmatningen är en fråga: ge ett sammanhängande, informativt svar baserat på kontexten.\n"
			. "Om svaret inte finns i kontexten: säg det tydligt istället för att gissa.\n"
			. "Svara alltid på svenska. Var koncis men informativ.\n"
			. "Ange gärna vilken källa ($sourceList) informationen kommer från.";
	}

	/**
	 * Return a human-readable label for a collection name.
	 */
	public static function getCollectionLabel($collection)
	{
		$labels = [
			'buf-digitalisering' => 'BUF Digitalisering',
			'fokus-ai'           => 'Fokus AI',
			'unikum-guider'      => 'Unikum Guider',
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
			if (!empty($payload[$field]) && is_string($payload[$field]) && filter_var($payload[$field], FILTER_VALIDATE_URL)) {
				return $payload[$field];
			}
		}

		return '';
	}
}
