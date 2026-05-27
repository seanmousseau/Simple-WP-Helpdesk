<?php
/**
 * PHPUnit bootstrap for Simple WP Helpdesk unit tests.
 *
 * Initialises WP_Mock and defines the WordPress constants required by plugin
 * files so they can be loaded without a full WordPress environment.
 *
 * @package Simple_WP_Helpdesk
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// WordPress constants used by plugin files.
define( 'ABSPATH', '/tmp/wp/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'MINUTE_IN_SECONDS', 60 );

// Plugin path constants (mirrors simple-wp-helpdesk.php bootstrap).
define( 'SWH_PLUGIN_DIR', dirname( __DIR__ ) . '/simple-wp-helpdesk/' );
define( 'SWH_PLUGIN_URL', 'https://example.com/wp-content/plugins/simple-wp-helpdesk/' );
define( 'SWH_PLUGIN_FILE', SWH_PLUGIN_DIR . 'simple-wp-helpdesk.php' );
define( 'SWH_VERSION', '3.1.0' );

WP_Mock::setUsePatchwork( false );
WP_Mock::bootstrap();

// WordPress class stubs for tests that exercise code using WP classes directly.

if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * Minimal WP_Query stub for unit tests.
	 * The stub always returns found_posts = 0; tests that need a specific count
	 * should work with the return value of swh_report_status_breakdown() directly.
	 */
	class WP_Query {
		/** @var int */
		public int $found_posts = 0;

		/** @var int[]|object[] */
		public array $posts = array();

		/** @param array<string, mixed> $args Query arguments (ignored in stub). */
		public function __construct( array $args = array() ) {}
	}
}

if ( ! class_exists( 'WP_REST_Controller' ) ) {
	/**
	 * Minimal WP_REST_Controller stub for unit tests.
	 */
	abstract class WP_REST_Controller {
		/** @var string */
		protected $namespace = '';

		/** @var string */
		protected $rest_base = '';

		/** @var array<string,mixed>|null */
		protected $schema = null;

		abstract public function register_routes(): void;

		/**
		 * @return array<string,mixed>
		 */
		public function get_item_schema(): array {
			return array();
		}

		/**
		 * @param string $method HTTP method.
		 * @return array<string,mixed>
		 */
		public function get_endpoint_args_for_item_schema( $method = 'POST' ): array {
			return array();
		}

		/**
		 * @return array<string,mixed>
		 */
		public function get_public_item_schema(): array {
			return $this->get_item_schema();
		}

		/**
		 * @param array<string,mixed> $schema
		 * @return array<string,mixed>
		 */
		public function add_additional_fields_schema( $schema ): array {
			return $schema;
		}
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	/**
	 * Minimal WP_REST_Server stub for unit tests.
	 */
	class WP_REST_Server {
		const READABLE  = 'GET';
		const EDITABLE  = 'POST, PUT, PATCH';
		const CREATABLE = 'POST';
		const DELETABLE = 'DELETE';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal WP_REST_Request stub for unit tests.
	 */
	class WP_REST_Request {
		/** @var array<string,mixed> */
		private array $params = array();

		/** @var array<string,string> */
		private array $headers = array();

		/**
		 * @param string $method HTTP method.
		 * @param string $route  Route string.
		 */
		public function __construct( string $method = 'GET', string $route = '' ) {}

		/**
		 * @param string $key   Parameter name.
		 * @param mixed  $value Parameter value.
		 */
		public function set_param( string $key, $value ): void {
			$this->params[ $key ] = $value;
		}

		/**
		 * @param string $key Parameter name.
		 * @return mixed
		 */
		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		/**
		 * @param string $name  Header name (case-insensitive).
		 * @param string $value Header value.
		 */
		public function set_header( string $name, string $value ): void {
			$this->headers[ strtolower( $name ) ] = $value;
		}

		/**
		 * @param string $name Header name (case-insensitive).
		 * @return string Empty string when unset (mirrors WP behaviour).
		 */
		public function get_header( string $name ): string {
			return $this->headers[ strtolower( $name ) ] ?? '';
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stub for unit tests.
	 *
	 * Mirrors the subset of WP core's WP_Error surface used by the v4.1.0
	 * REST error envelope ({@see \SWH\Rest\Errors}). `data.status` is the
	 * field WP core copies into the HTTP response status when handing the
	 * error to `WP_REST_Server::error_to_response()`.
	 */
	class WP_Error {
		/** @var string */
		private string $code;

		/** @var string */
		private string $message;

		/** @var array<string,mixed> */
		private array $data;

		/**
		 * @param string              $code    Error code.
		 * @param string              $message Human-readable message.
		 * @param array<string,mixed> $data    Arbitrary data — `status` becomes the HTTP status.
		 */
		public function __construct( string $code = '', string $message = '', array $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		/**
		 * @return array<string,mixed>
		 */
		public function get_error_data(): array {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Minimal WP_REST_Response stub for unit tests.
	 */
	class WP_REST_Response {
		/** @var mixed */
		private $data;

		/** @var int */
		private int $status;

		/** @var array<string,string> */
		private array $headers = array();

		/**
		 * @param mixed $data   Response data.
		 * @param int   $status HTTP status code.
		 */
		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		/** @return mixed */
		public function get_data() {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}

		/**
		 * @param string $name  Header name (case-insensitive).
		 * @param string $value Header value.
		 */
		public function header( string $name, string $value ): void {
			$this->headers[ strtolower( $name ) ] = $value;
		}

		/**
		 * @return array<string,string>
		 */
		public function get_headers(): array {
			return $this->headers;
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Minimal WP_Post stub for unit tests.
	 *
	 * Mirrors the public-property surface unit tests need. Real WP_Post is
	 * loaded from wp-includes/class-wp-post.php in a full WP environment.
	 */
	class WP_Post {
		public int $ID = 0;
		public string $post_type = '';
		public string $post_title = '';
		public string $post_content = '';
		public string $post_status = 'publish';
		public string $post_date_gmt = '';
		public string $post_modified_gmt = '';

		/**
		 * @param array<string,mixed> $data Optional property overrides.
		 */
		public function __construct( array $data = array() ) {
			foreach ( $data as $key => $value ) {
				if ( property_exists( $this, $key ) ) {
					$this->{$key} = $value;
				}
			}
		}
	}
}

if ( ! class_exists( 'WP_Comment' ) ) {
	/**
	 * Minimal WP_Comment stub for unit tests.
	 *
	 * Defines the properties accessed by swh_format_comment_date() so tests can
	 * construct real WP_Comment objects without a full WordPress environment.
	 */
	class WP_Comment {
		/** @var string UTC timestamp of the comment. */
		public string $comment_date_gmt = '';

		/** @var string Comment ID (WP serialises as string). */
		public string $comment_ID = '0';

		/** @var string Parent post ID (WP serialises as string). */
		public string $comment_post_ID = '0';

		/** @var string Author user ID (0 for guests/clients). */
		public string $user_id = '0';

		/** @var string Author display name. */
		public string $comment_author = '';

		/** @var string Author email. */
		public string $comment_author_email = '';

		/** @var string Comment body. */
		public string $comment_content = '';

		/** @var string Comment type (e.g. helpdesk_reply). */
		public string $comment_type = '';

		/**
		 * @param array<string, mixed> $data Optional property overrides.
		 */
		public function __construct( array $data = array() ) {
			foreach ( $data as $key => $value ) {
				if ( property_exists( $this, $key ) ) {
					$this->{$key} = (string) $value;
				}
			}
		}
	}
}

// Minimal WordPress function stubs required by unit tests.
// WP_Mock does not define every WordPress function; define those needed here.

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Stub: strip tags and trim (matches WP behaviour for simple strings).
	 *
	 * @param string $str Input string.
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stub only; real WP is not available.
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	/**
	 * Stub: replaces spaces with hyphens and lowercases (core WP behaviour).
	 *
	 * @param string $filename Input filename.
	 * @return string
	 */
	function sanitize_file_name( $filename ) {
		return strtolower( preg_replace( '/\s+/', '-', (string) $filename ) );
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Stub: pass-through gettext for unit tests.
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain (ignored).
	 * @return string
	 */
	function __( $text, $domain = '' ) { // phpcs:ignore
		return (string) $text;
	}
}

if ( ! function_exists( '__return_true' ) ) {
	function __return_true(): bool {
		return true;
	}
}

if ( ! function_exists( '__return_false' ) ) {
	function __return_false(): bool {
		return false;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Stub: returns true when $thing is a WP_Error instance.
	 *
	 * @param mixed $thing Value to test.
	 * @return bool
	 */
	function is_wp_error( $thing ): bool {
		return $thing instanceof \WP_Error;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Stub: lowercase alphanumeric and hyphens only (matches WP behaviour).
	 *
	 * @param string $key Input key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
