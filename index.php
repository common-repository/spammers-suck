<?php

/**
* Plugin Name: Spam Hammer
* Author: wpspamhammer
* Author URI: http://www.wpspamhammer.com
* Description: No moderation, no captchas, no puzzles, no false positives.  Simple.
* Version: 3.9.7.1
**/

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-includes/pluggable.php';

define('SPAM_HAMMER_DIR', WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'spammers-suck');

require_once SPAM_HAMMER_DIR . '/includes/functions.php';

SpamHammer::defaultOptions();
SpamHammer::defaultFilters();

if (current_user_can('administrator')) {
	if (basename($_SERVER['SCRIPT_FILENAME']) == 'options.php' && $_POST['action'] == 'update' && $_POST['option_page'] == "spam_hammer") {
		SpamHammer::updateOptions();
	}

	SpamHammer::adminInit();
}

if (isset($_GET['spam_hammer_script'])) {
	header('Content-Type: text/html');
	header('Cache-Control: no-cache, no-store, must-revalidate');
	header('Pragma: no-cache');
	header('Expires: 0');

	$markup = SpamHammer_Network::get('spamhammer', 'render_form', array(
		'ip_address' => $_SERVER['REMOTE_ADDR'],
		'template' => 'script'
	));

	if ($markup && !is_array($markup)) {
		echo $markup;
	}

	exit;
}