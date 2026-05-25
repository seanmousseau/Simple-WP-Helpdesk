<?php
/**
 * Unit tests for CSAT settings introduced in v4.0.3 (#471).
 *
 * Asserts:
 *   - The four `swh_csat_*` keys are present in `swh_get_defaults()` with the
 *     expected default values.
 *   - The migration map routes each `swh_csat_*` key to the `portal` group.
 *
 * @package Simple_WP_Helpdesk
 */

use WP_Mock\Tools\TestCase;
use SWH\Settings\Migration;

class CsatSettingsTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		require_once SWH_PLUGIN_DIR . 'includes/helpers.php';
	}

	/** csat_enabled defaults to true so existing sites keep the widget on auto-upgrade. */
	public function test_csat_enabled_default_is_true(): void {
		$defaults = swh_get_defaults();
		$this->assertArrayHasKey( 'swh_csat_enabled', $defaults );
		$this->assertTrue( $defaults['swh_csat_enabled'] );
	}

	/** csat_prompt default matches the legacy hardcoded string so unconfigured sites look identical. */
	public function test_csat_prompt_default_matches_legacy_hardcoded_string(): void {
		$defaults = swh_get_defaults();
		$this->assertSame( 'How was your support experience?', $defaults['swh_csat_prompt'] );
	}

	/** csat_thanks default matches the legacy hardcoded string. */
	public function test_csat_thanks_default_matches_legacy_hardcoded_string(): void {
		$defaults = swh_get_defaults();
		$this->assertSame( 'Thanks for your feedback!', $defaults['swh_csat_thanks'] );
	}

	/** csat_skip_label default matches the legacy hardcoded string. */
	public function test_csat_skip_label_default_matches_legacy_hardcoded_string(): void {
		$defaults = swh_get_defaults();
		$this->assertSame( 'Skip', $defaults['swh_csat_skip_label'] );
	}

	/**
	 * Each CSAT flat key maps to the `portal` group with the prefix stripped.
	 *
	 * @dataProvider csat_keys_provider
	 */
	public function test_csat_keys_map_to_portal_group( string $flat_key, string $expected_new_key ): void {
		$entry = Migration::group_for( $flat_key );

		$this->assertNotNull( $entry, "Migration map is missing entry for {$flat_key}." );
		$this->assertSame( 'portal', $entry[0], "{$flat_key} must be in the portal group." );
		$this->assertSame( $expected_new_key, $entry[1] );
	}

	public static function csat_keys_provider(): array {
		return array(
			array( 'swh_csat_enabled', 'csat_enabled' ),
			array( 'swh_csat_prompt', 'csat_prompt' ),
			array( 'swh_csat_thanks', 'csat_thanks' ),
			array( 'swh_csat_skip_label', 'csat_skip_label' ),
		);
	}
}
