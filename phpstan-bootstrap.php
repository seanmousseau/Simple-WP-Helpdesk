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
define( 'SWH_VERSION', '2.1.0' );

