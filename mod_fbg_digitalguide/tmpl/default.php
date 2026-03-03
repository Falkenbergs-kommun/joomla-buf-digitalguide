<?php
/**
 * @package    FBG Digital Guide
 *
 * @author     Falkenbergs kommun <utveckling@falkenberg.se>
 * @copyright  2026
 * @license    NA
 * @link       falkenberg.se
 */

defined('_JEXEC') or die;
?>

<div class="mod-fbg-digitalguide<?php echo $moduleclass_sfx ? ' ' . $moduleclass_sfx : ''; ?>">

	<?php if ($heading) : ?>
	<div class="fbg-dg-heading-row uk-margin-small-bottom">
		<span uk-icon="icon: search; ratio: 1.2" class="fbg-dg-heading-icon"></span>
		<span class="fbg-dg-heading-text"><?php echo $heading; ?></span>
	</div>
	<?php endif; ?>

	<!-- Search form -->
	<div class="fbg-dg-search-wrapper" role="search">
		<label for="fbg-dg-input" class="uk-visible-sr"><?php echo $placeholder; ?></label>
		<div class="fbg-dg-search-row">
			<input
				type="search"
				id="fbg-dg-input"
				class="uk-input fbg-dg-input"
				placeholder="<?php echo $placeholder; ?>"
				autocomplete="off"
			>
			<button
				id="fbg-dg-submit"
				class="uk-button uk-button-primary fbg-dg-btn"
				type="button"
				aria-label="Sök"
			>
				<span uk-icon="icon: arrow-right; ratio: 1.1"></span>
			</button>
		</div>
		<div class="fbg-dg-hint uk-text-muted uk-text-small uk-margin-small-top">
			Skriv ett nyckelord för att söka, eller ställ en fråga för ett sammanfattande svar.
		</div>
	</div>

	<!-- Loading indicator -->
	<div id="fbg-dg-loading" class="fbg-dg-loading uk-hidden">
		<div class="uk-flex uk-flex-middle uk-margin-top">
			<div uk-spinner="ratio: 0.75"></div>
			<span id="fbg-dg-loading-text" class="uk-margin-small-left uk-text-muted">Söker i kunskapsbasen…</span>
		</div>
	</div>

	<!-- Answer section -->
	<div id="fbg-dg-result" class="fbg-dg-result uk-hidden">

		<div class="fbg-dg-answer uk-margin-top">
			<div class="fbg-dg-answer-header">
				<span class="fbg-dg-answer-label">Svar</span>
				<button id="fbg-dg-copy-btn" class="fbg-dg-copy-btn" type="button" title="Kopiera svar">
					<span uk-icon="icon: copy; ratio: 0.9"></span>
				</button>
			</div>
			<div id="fbg-dg-answer-text" class="fbg-dg-answer-text"></div>
		</div>

		<?php if ($showSources) : ?>
		<div id="fbg-dg-sources-wrapper" class="fbg-dg-sources uk-hidden">
			<div class="fbg-dg-sources-label uk-text-muted uk-text-small uk-margin-small-bottom">
				<span uk-icon="icon: file-text; ratio: 0.85"></span> Källor
			</div>
			<ul id="fbg-dg-sources-list" class="fbg-dg-sources-list uk-list"></ul>
		</div>
		<?php endif; ?>

		<div class="fbg-dg-new-search-row uk-margin-top">
			<button id="fbg-dg-new-search" class="uk-button uk-button-default uk-button-small" type="button">
				<span uk-icon="icon: refresh; ratio: 0.85"></span> Ny sökning
			</button>
		</div>
	</div>

	<!-- Error section -->
	<div id="fbg-dg-error" class="fbg-dg-error uk-hidden">
		<div class="uk-alert-danger uk-alert uk-margin-top" uk-alert>
			<span uk-icon="icon: warning; ratio: 0.9"></span>
			<span id="fbg-dg-error-text"></span>
		</div>
		<button id="fbg-dg-retry" class="uk-button uk-button-default uk-button-small" type="button">
			Försök igen
		</button>
	</div>

</div>
