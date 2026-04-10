<?php
/**
 * PHPStan stub for bundled plugin-update-checker vendor library.
 *
 * PucFactory uses a conditional class_exists guard that PHPStan cannot evaluate
 * statically. This stub declares a minimal class so PHPStan can resolve the
 * YahnisElsts\PluginUpdateChecker\v5p6\PucFactory::buildUpdateChecker() call
 * in the plugin bootstrap without adding phpstan-ignore directives.
 */

namespace YahnisElsts\PluginUpdateChecker\v5p6;

if ( ! class_exists( PucFactory::class ) ) {
	class PucFactory {
		/**
		 * @param string $metadataUrl
		 * @param string $fullPath
		 * @param string $slug
		 * @return mixed
		 */
		public static function buildUpdateChecker( $metadataUrl, $fullPath, $slug = '' ) {
			return null;
		}
	}
}

namespace YahnisElsts\PluginUpdateChecker\v5p6\Plugin;

if ( ! class_exists( PluginInfo::class ) ) {
	class PluginInfo {
		/** @var array<string, string> */
		public $icons = array();
	}
}
