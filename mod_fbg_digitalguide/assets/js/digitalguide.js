/**
 * FBG Digital Guide – RAG-baserad sök- och frågebesvaringstjänst
 *
 * Använder fetch() + ReadableStream för SSE-streaming från stream.php.
 *
 * Flöde:
 * 1. Användaren skriver och skickar fråga/sökord
 * 2. POST till stream.php startar RAG-pipelinen
 * 3. 'sources'-event → källdokument renderas omedelbart
 * 4. 'chunk'-events → text växer fram tecken för tecken
 * 5. Vid stream-slut → markdown-rendering av det fullständiga svaret
 *
 * @package FBG Digital Guide
 */

(function () {
	'use strict';

	// ── Vänta på att jQuery och DOM är redo ──────────────────────────────────

	function defer(fn) {
		if (typeof jQuery !== 'undefined') {
			jQuery(fn);
		} else {
			setTimeout(function () { defer(fn); }, 50);
		}
	}

	defer(function () {
		if (jQuery('#fbg-dg-input').length) {
			initDigitalGuide();
		}
	});

	// ── Initiering ───────────────────────────────────────────────────────────

	function initDigitalGuide() {
		jQuery('#fbg-dg-submit').on('click', function () {
			doSearch();
		});

		jQuery('#fbg-dg-input').on('keydown', function (e) {
			if (e.key === 'Enter' || e.keyCode === 13) {
				e.preventDefault();
				doSearch();
			}
		});

		jQuery('#fbg-dg-new-search').on('click', function () {
			resetToSearch();
		});

		jQuery('#fbg-dg-retry').on('click', function () {
			doSearch();
		});

		jQuery('#fbg-dg-copy-btn').on('click', function () {
			var text = jQuery('#fbg-dg-answer-text').text();
			if (navigator.clipboard && text) {
				navigator.clipboard.writeText(text).then(function () {
					showCopyFeedback(jQuery('#fbg-dg-copy-btn'));
				});
			}
		});
	}

	// ── Sök med SSE-streaming ────────────────────────────────────────────────

	async function doSearch() {
		var query = jQuery('#fbg-dg-input').val().trim();

		if (!query) {
			jQuery('#fbg-dg-input').addClass('fbg-dg-input--invalid');
			setTimeout(function () {
				jQuery('#fbg-dg-input').removeClass('fbg-dg-input--invalid');
			}, 800);
			return;
		}

		showLoading(isQuestion(query));

		var formData = new FormData();
		formData.append('question', query);

		var fullText    = '';
		var streamDone  = false;

		try {
			var response = await fetch(fbgDigitalguideConfig.streamUrl, {
				method: 'POST',
				body:   formData,
			});

			if (!response.ok) {
				throw new Error('HTTP ' + response.status);
			}

			// Visa svarsytan med blinkande cursor direkt
			hideLoading();
			jQuery('#fbg-dg-answer-text').html('<span class="fbg-dg-cursor"></span>');
			jQuery('#fbg-dg-sources-wrapper').addClass('uk-hidden');
			jQuery('#fbg-dg-result').removeClass('uk-hidden');
			jQuery('#fbg-dg-error').addClass('uk-hidden');

			var reader  = response.body.getReader();
			var decoder = new TextDecoder();
			var buffer  = '';

			// Läs stream
			while (true) {
				var result = await reader.read();

				if (result.done) {
					break;
				}

				buffer += decoder.decode(result.value, { stream: true });

				// SSE-block separeras av \n\n
				var blocks = buffer.split('\n\n');
				buffer = blocks.pop(); // Behåll ofullständigt block

				for (var i = 0; i < blocks.length; i++) {
					var block     = blocks[i];
					var eventType = 'message';
					var data      = '';

					var lines = block.split('\n');
					for (var j = 0; j < lines.length; j++) {
						var line = lines[j];
						if (line.startsWith('event: ')) {
							eventType = line.slice(7).trim();
						} else if (line.startsWith('data: ')) {
							data = line.slice(6).trim();
						}
					}

					if (!data) {
						continue;
					}

					var payload;
					try {
						payload = JSON.parse(data);
					} catch (e) {
						continue;
					}

					if (eventType === 'sources') {
						if (fbgDigitalguideConfig.showSources && payload.sources && payload.sources.length > 0) {
							renderSources(payload.sources);
							jQuery('#fbg-dg-sources-wrapper').removeClass('uk-hidden');
						}

					} else if (eventType === 'chunk') {
						fullText += payload.text || '';
						// Visa strömmande text med blinkande cursor
						jQuery('#fbg-dg-answer-text').html(
							renderStreamingText(fullText) + '<span class="fbg-dg-cursor"></span>'
						);

					} else if (eventType === 'error') {
						streamDone = true;
						jQuery('#fbg-dg-result').addClass('uk-hidden');
						showError(payload.message || 'Okänt fel uppstod.');
						break;
					}
				}

				if (streamDone) {
					break;
				}
			}

		} catch (err) {
			hideLoading();
			showError('Kommunikationsfel – ' + err.message);
			return;
		}

		// Ström slut: ersätt rå text med korrekt markdown-rendering
		if (fullText && !streamDone) {
			jQuery('#fbg-dg-answer-text').html(renderMarkdown(fullText));
		}
	}

	// ── Heuristik: sökord vs fråga ───────────────────────────────────────────

	function isQuestion(text) {
		if (text.includes('?')) {
			return true;
		}

		var questionWords = [
			'vad', 'vem', 'var', 'när', 'varför', 'hur', 'vilket', 'vilka', 'vilken',
			'kan', 'är', 'finns', 'har', 'bör', 'ska', 'måste', 'får', 'behöver',
			'what', 'who', 'where', 'when', 'why', 'how', 'which', 'does', 'is', 'are'
		];

		var firstWord = text.trim().toLowerCase().split(/\s+/)[0];
		if (questionWords.indexOf(firstWord) !== -1) {
			return true;
		}

		return text.trim().split(/\s+/).length > 4;
	}

	// ── Visa/dölj tillstånd ──────────────────────────────────────────────────

	function showLoading(isQ) {
		jQuery('#fbg-dg-loading-text').text(
			isQ ? 'Analyserar frågan och söker i kunskapsbasen…' : 'Söker i kunskapsbasen…'
		);
		jQuery('#fbg-dg-loading').removeClass('uk-hidden');
		jQuery('#fbg-dg-result').addClass('uk-hidden');
		jQuery('#fbg-dg-error').addClass('uk-hidden');
		jQuery('#fbg-dg-submit').prop('disabled', true);
	}

	function hideLoading() {
		jQuery('#fbg-dg-loading').addClass('uk-hidden');
		jQuery('#fbg-dg-submit').prop('disabled', false);
	}

	function showError(message) {
		hideLoading();
		jQuery('#fbg-dg-error-text').text(message);
		jQuery('#fbg-dg-error').removeClass('uk-hidden');
	}

	function resetToSearch() {
		jQuery('#fbg-dg-result').addClass('uk-hidden');
		jQuery('#fbg-dg-error').addClass('uk-hidden');
		jQuery('#fbg-dg-input').val('').focus();
	}

	// ── Rendera källdokument med länkade titlar ───────────────────────────────

	function renderSources(sources) {
		var $list = jQuery('#fbg-dg-sources-list').empty();

		sources.forEach(function (source) {
			var label   = escapeHtml(source.collection_label || source.collection || '');
			var score   = source.score || 0;
			var snippet = source.snippet ? escapeHtml(source.snippet) : '';

			var scoreClass = score >= 75 ? 'fbg-dg-score--high'
				: score >= 50          ? 'fbg-dg-score--medium'
				:                        'fbg-dg-score--low';

			// Titel – länkad om URL finns
			var titleHtml = '';
			if (source.title || source.url) {
				var labelText = escapeHtml(source.title || source.url);
				if (source.url) {
					titleHtml = '<div class="fbg-dg-source-title">'
						+ '<a href="' + escapeHtml(source.url) + '" target="_blank" rel="noopener noreferrer">'
						+ '<span uk-icon="icon: link; ratio: 0.75"></span> ' + labelText
						+ '</a></div>';
				} else {
					titleHtml = '<div class="fbg-dg-source-title">' + labelText + '</div>';
				}
			}

			var html = '<li class="fbg-dg-source-item">'
				+ '<div class="fbg-dg-source-meta">'
				+ '<span class="fbg-dg-collection-badge">' + label + '</span>'
				+ '<span class="fbg-dg-score ' + scoreClass + '">' + score + '%</span>'
				+ '</div>'
				+ titleHtml
				+ (snippet ? '<div class="fbg-dg-source-snippet">' + snippet + '</div>' : '')
				+ '</li>';

			$list.append(html);
		});
	}

	// ── Text-rendering ────────────────────────────────────────────────────────

	/**
	 * Under streaming: visa rå text med enkel radbrytning.
	 * Markdown-tecken (**, #, -) visas som de är tills strömmen är klar.
	 */
	function renderStreamingText(text) {
		return escapeHtml(text).replace(/\n/g, '<br>');
	}

	/**
	 * Slutlig rendering: konverterar Markdown till HTML.
	 */
	function renderMarkdown(text) {
		if (!text) {
			return '';
		}

		var lines = text.split('\n');
		var html  = '';
		var inUl  = false;
		var inOl  = false;

		for (var i = 0; i < lines.length; i++) {
			var line = lines[i];

			// Rubriker
			if (/^### /.test(line)) {
				closeList();
				html += '<h5>' + inline(line.slice(4)) + '</h5>';
				continue;
			}
			if (/^## /.test(line)) {
				closeList();
				html += '<h4>' + inline(line.slice(3)) + '</h4>';
				continue;
			}
			if (/^# /.test(line)) {
				closeList();
				html += '<h3>' + inline(line.slice(2)) + '</h3>';
				continue;
			}

			// Punktlista
			if (/^[*-] /.test(line)) {
				if (inOl) { html += '</ol>'; inOl = false; }
				if (!inUl) { html += '<ul>'; inUl = true; }
				html += '<li>' + inline(line.slice(2)) + '</li>';
				continue;
			}

			// Numrerad lista
			if (/^\d+\. /.test(line)) {
				if (inUl) { html += '</ul>'; inUl = false; }
				if (!inOl) { html += '<ol>'; inOl = true; }
				html += '<li>' + inline(line.replace(/^\d+\. /, '')) + '</li>';
				continue;
			}

			closeList();

			if (line.trim() === '') {
				html += '</p><p>';
			} else {
				html += inline(line) + ' ';
			}
		}

		closeList();

		html = '<p>' + html + '</p>';
		// Städa tomma paragrafer och korrigera block-element i p-taggar
		html = html.replace(/<p>\s*<\/p>/g, '');
		html = html.replace(/<p>(<h[3-5]>)/g, '$1');
		html = html.replace(/(<\/h[3-5]>)<\/p>/g, '$1');
		html = html.replace(/<p>(<[uo]l>)/g, '$1');
		html = html.replace(/(<\/[uo]l>)<\/p>/g, '$1');

		return html;

		function closeList() {
			if (inUl) { html += '</ul>'; inUl = false; }
			if (inOl) { html += '</ol>'; inOl = false; }
		}
	}

	/** Inline-markdown: fetstil, kursiv, kod */
	function inline(text) {
		text = escapeHtml(text);
		text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
		text = text.replace(/\*(.+?)\*/g, '<em>$1</em>');
		text = text.replace(/_(.+?)_/g, '<em>$1</em>');
		text = text.replace(/`(.+?)`/g, '<code>$1</code>');
		return text;
	}

	// ── Hjälpfunktioner ──────────────────────────────────────────────────────

	function escapeHtml(text) {
		if (typeof text !== 'string') { return ''; }
		return text
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function showCopyFeedback($btn) {
		$btn.find('[uk-icon]').attr('uk-icon', 'icon: check');
		$btn.addClass('fbg-dg-copy-btn--copied');
		setTimeout(function () {
			$btn.find('[uk-icon]').attr('uk-icon', 'icon: copy; ratio: 0.9');
			$btn.removeClass('fbg-dg-copy-btn--copied');
		}, 2000);
	}

}());
