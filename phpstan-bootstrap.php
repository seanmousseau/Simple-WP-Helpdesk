<?php
/**
 * PHPStan bootstrap: define plugin constants for static analysis.
 *
 * These constants are defined at runtime in simple-wp-helpdesk.php; this file
 * makes them available to PHPStan so analysis runs without "constant not found"
 * errors across module files.
 */
define( 'SWH_PLUGIN_URL', '' );
define( 'SWH_PLUGIN_DIR', '' );
define( 'SWH_PLUGIN_FILE', '' );
define( 'SWH_VERSION', '3.1.0' );
define( 'SWH_ICON_1X', SWH_PLUGIN_URL . 'assets/icon-128x128.png' );
define( 'SWH_ICON_2X', SWH_PLUGIN_URL . 'assets/icon-256x256.png' );
define( 'SWH_MENU_ICON', SWH_PLUGIN_URL . 'assets/favicon-32.png' );

