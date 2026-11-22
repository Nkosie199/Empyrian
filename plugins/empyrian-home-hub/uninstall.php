<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/class-empyrian-hub-history.php';

delete_transient( 'empyrian_hub_releases' );
Empyrian_Hub_History::forget_everyone();
