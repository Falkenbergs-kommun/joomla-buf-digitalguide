<?php
/**
 * @package    FBG Digital Guide
 *
 * @author     Falkenbergs kommun <utveckling@falkenberg.se>
 * @copyright  2026
 * @license    NA
 * @link       falkenberg.se
 */

use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;

defined('_JEXEC') or die;

// Load helper class
require_once __DIR__ . '/helper.php';

// Get module parameters
$moduleclass_sfx = htmlspecialchars($params->get('moduleclass_sfx', ''));
$placeholder     = htmlspecialchars($params->get('placeholder', 'Sök eller ställ en fråga...'));
$showSources     = (int)$params->get('show_sources', 1);

// Get document object
$document = Factory::getDocument();

// Load module-specific JavaScript and CSS
$document->addScript('/modules/mod_fbg_digitalguide/assets/js/digitalguide.js?v=2');
$document->addStyleSheet('/modules/mod_fbg_digitalguide/assets/css/digitalguide.css?v=2');

// Pass configuration to JavaScript
$document->addScriptDeclaration("
	var fbgDigitalguideConfig = {
		ajaxUrl: '" . Uri::root() . "index.php?option=com_ajax&module=fbg_digitalguide&format=json',
		streamUrl: '/modules/mod_fbg_digitalguide/stream.php',
		moduleId: " . (int)$module->id . ",
		showSources: " . ($showSources ? 'true' : 'false') . "
	};
");

// Include template
require ModuleHelper::getLayoutPath('mod_fbg_digitalguide', $params->get('layout', 'default'));
