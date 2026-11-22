<?php
/**
 * Plugin Name: Empyrian Home Hub
 * Description: Turns the front page into a listener's home: Continue listening, Radio, Charts, New releases, For you and Upload. Also serves the same sections as Hub Cards at /wp-json/empyrian/v1/hub/cards.
 * Version: 1.0.0
 * Requires PHP: 8.1
 */

defined( 'ABSPATH' ) || exit;

const EMPYRIAN_HUB_VERSION = '1.0.0';

require_once __DIR__ . '/src/class-empyrian-hub-playable.php';
require_once __DIR__ . '/src/class-empyrian-hub-history.php';
require_once __DIR__ . '/src/class-empyrian-hub-catalog.php';
require_once __DIR__ . '/src/class-empyrian-hub-radio.php';
require_once __DIR__ . '/src/class-empyrian-hub-sections.php';
require_once __DIR__ . '/src/class-empyrian-hub-page.php';
require_once __DIR__ . '/src/class-empyrian-hub-rest.php';
require_once __DIR__ . '/src/class-empyrian-home-hub.php';

Empyrian_Home_Hub::register( __FILE__ );
