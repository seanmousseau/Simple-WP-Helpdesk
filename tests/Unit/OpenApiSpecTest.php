<?php
declare(strict_types=1);

namespace SWH\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWH\Rest\Errors;

/**
 * Drift detector for `docs/openapi.json` — the committed build artifact that
 * the public `/swh/v1/openapi.json` endpoint serves.
 *
 * Phase 8 of v4.1.0 (#368). These checks fail loudly when somebody:
 *
 *  - Edits `docs/openapi.yaml` but forgets to regenerate the JSON
 *    (`bin/openapi-sync.sh` then `git add docs/openapi.json`).
 *  - Adds a new route to `Schemas/*` without surfacing it in the spec.
 *  - Adds a new error code to `\SWH\Rest\Errors` without enumerating it in
 *    the spec's shared `Error.code.enum`.
 *  - Bumps the plugin header version but leaves `info.version` stale.
 *
 * The spec source-of-truth file is the YAML; the JSON is a build artifact.
 * CI also runs `bin/openapi-sync.sh` and `git diff --exit-code` to catch the
 * same drift at the workflow level — these PHP tests are the local-gate
 * safety net.
 */
final class OpenApiSpecTest extends TestCase {

	/**
	 * @return array<string,mixed>
	 */
	private function load_spec(): array {
		$path = dirname( __DIR__, 2 ) . '/docs/openapi.json';
		$this->assertFileExists( $path, 'docs/openapi.json must be committed (run bin/openapi-sync.sh).' );
		$raw = file_get_contents( $path );
		$this->assertIsString( $raw );
		$data = json_decode( $raw, true );
		$this->assertIsArray( $data, 'docs/openapi.json must contain a valid JSON object.' );
		return $data;
	}

	public function test_spec_is_openapi_3_1(): void {
		$spec = $this->load_spec();
		$this->assertArrayHasKey( 'openapi', $spec );
		$this->assertStringStartsWith( '3.1', (string) $spec['openapi'] );
	}

	public function test_info_version_matches_plugin_header(): void {
		$spec = $this->load_spec();
		$this->assertArrayHasKey( 'info', $spec );
		$this->assertArrayHasKey( 'version', $spec['info'] );

		// Pull the plugin header version from the main plugin file. This is
		// the single source of truth bumped at release time.
		$plugin_file = dirname( __DIR__, 2 ) . '/simple-wp-helpdesk/simple-wp-helpdesk.php';
		$contents    = (string) file_get_contents( $plugin_file );
		if ( ! preg_match( '/^\s*\*\s*Version:\s*([\d.]+)/m', $contents, $m ) ) {
			$this->fail( 'Could not extract plugin Version header from ' . $plugin_file );
		}
		$plugin_version = $m[1];

		$this->assertSame(
			$plugin_version,
			(string) $spec['info']['version'],
			'docs/openapi.yaml info.version must match the plugin header version.'
		);
	}

	public function test_every_registered_v1_path_is_in_the_spec(): void {
		$spec = $this->load_spec();
		$this->assertArrayHasKey( 'paths', $spec );

		// Locked v4.1.0 surface — every route registered by a `src/Rest/V1`
		// controller must be in the spec.
		$expected = array(
			'/swh/v1/tickets',
			'/swh/v1/tickets/{id}',
			'/swh/v1/tickets/{id}/replies',
			'/swh/v1/users/technicians',
			'/swh/v1/categories',
			'/swh/v1/settings/public',
			'/swh/v1/openapi.json',
		);
		foreach ( $expected as $path ) {
			$this->assertArrayHasKey( $path, $spec['paths'], "Spec is missing path {$path}" );
		}
	}

	public function test_every_v1_schema_class_has_a_component_schema(): void {
		$spec = $this->load_spec();
		$this->assertArrayHasKey( 'components', $spec );
		$this->assertArrayHasKey( 'schemas', $spec['components'] );

		$dir = dirname( __DIR__, 2 ) . '/simple-wp-helpdesk/src/Rest/V1/Schemas';
		$this->assertDirectoryExists( $dir );
		foreach ( glob( $dir . '/*.php' ) as $file ) {
			$name = basename( $file, '.php' );
			$this->assertArrayHasKey(
				$name,
				$spec['components']['schemas'],
				"Spec is missing component schema {$name} for src/Rest/V1/Schemas/{$name}.php"
			);
		}
	}

	public function test_every_error_constant_appears_in_error_enum(): void {
		$spec = $this->load_spec();
		$enum = $spec['components']['schemas']['Error']['properties']['code']['enum'] ?? array();
		$this->assertIsArray( $enum );
		$this->assertNotEmpty( $enum, 'Error schema must enumerate its code strings.' );

		$reflection = new \ReflectionClass( Errors::class );
		$constants  = $reflection->getConstants();
		$this->assertNotEmpty( $constants );

		foreach ( $constants as $name => $value ) {
			$this->assertIsString( $value );
			$this->assertContains(
				$value,
				$enum,
				"Errors::{$name} ('{$value}') must appear in the spec's Error.code.enum."
			);
		}
	}

	public function test_json_matches_yaml_source(): void {
		// CI also runs `bin/openapi-sync.sh && git diff --exit-code` to catch
		// drift between the human-edited YAML and the committed JSON. This
		// test is the local-gate safety net using PHP's lightweight YAML-ish
		// detection — we don't ship a full YAML parser, so we make do with a
		// few targeted checks: confirm both files declare the same OpenAPI
		// version and the same paths key set.
		$yaml_path = dirname( __DIR__, 2 ) . '/docs/openapi.yaml';
		$json      = $this->load_spec();
		$this->assertFileExists( $yaml_path );

		$yaml_raw = (string) file_get_contents( $yaml_path );

		// `openapi: 3.1.0` line in YAML must match the JSON.
		if ( ! preg_match( '/^openapi:\s*([\d.]+)/m', $yaml_raw, $m ) ) {
			$this->fail( 'docs/openapi.yaml must start with `openapi: <version>`.' );
		}
		$this->assertSame( $m[1], (string) $json['openapi'] );

		// Every JSON path must appear as a path key in the YAML source.
		foreach ( array_keys( $json['paths'] ) as $path ) {
			$needle = '  ' . $path . ':';
			$this->assertStringContainsString(
				$needle,
				$yaml_raw,
				"Path {$path} appears in docs/openapi.json but not in docs/openapi.yaml — run bin/openapi-sync.sh to regenerate."
			);
		}
	}
}
