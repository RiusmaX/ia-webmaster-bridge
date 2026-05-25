<?php
/**
 * Polyfills for the WordPress functions referenced by the plugin modules
 * under test.
 *
 * The goal is to make the production plugin code load and execute
 * against an empty PHP environment, without standing up a real WordPress
 * install. Each polyfill delegates the side effect to one of the
 * in-memory mock stores defined in `options.php`, `transients.php` and
 * `wpdb.php`.
 *
 * Only the surface area actually invoked by the modules under test is
 * implemented. Anything outside the critical-path test scope returns a
 * sensible default value (no-op).
 *
 * @package IA_Webmaster_Bridge\Tests
 */

require_once __DIR__ . '/options.php';
require_once __DIR__ . '/transients.php';
require_once __DIR__ . '/wpdb.php';

// ----------------------------------------------------------------------
// Constants used by WordPress core that pieces of the plugin may touch.
// ----------------------------------------------------------------------

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'ARRAY_N' ) ) {
	define( 'ARRAY_N', 'ARRAY_N' );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', __DIR__ . '/plugins' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// ----------------------------------------------------------------------
// $wpdb singleton — initialised once for the whole test run; reset
// between tests via WpdbMock::reset() from the test case setUp.
// ----------------------------------------------------------------------

global $wpdb;
if ( ! isset( $wpdb ) || ! ( $wpdb instanceof WpdbMock ) ) {
	$wpdb = new WpdbMock();
}

// ----------------------------------------------------------------------
// Options
// ----------------------------------------------------------------------

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return WpOptionsMock::get( $name, $default );
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		return WpOptionsMock::set( $name, $value );
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
		if ( WpOptionsMock::has( $name ) ) {
			return false;
		}
		return WpOptionsMock::set( $name, $value );
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		return WpOptionsMock::delete( $name );
	}
}

// ----------------------------------------------------------------------
// Transients
// ----------------------------------------------------------------------

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $name ) {
		return WpTransientsMock::get( $name );
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $name, $value, $expiration = 0 ) {
		return WpTransientsMock::set( $name, $value, $expiration );
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $name ) {
		return WpTransientsMock::delete( $name );
	}
}

// ----------------------------------------------------------------------
// JSON / sanitisation helpers
// ----------------------------------------------------------------------

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
		$pool = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		if ( $special_chars ) {
			$pool .= '!@#$%^&*()';
		}
		$out = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $pool[ random_int( 0, strlen( $pool ) - 1 ) ];
		}
		return $out;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		$value = (string) $value;
		$value = strip_tags( $value );
		$value = preg_replace( '/[\r\n\t]+/', ' ', $value );
		return trim( $value );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'esc_sql' ) ) {
	function esc_sql( $value ) {
		return addslashes( (string) $value );
	}
}

// ----------------------------------------------------------------------
// Time / locale
// ----------------------------------------------------------------------

if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() {
		return new DateTimeZone( 'UTC' );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) {
		if ( 'mysql' === $type ) {
			return gmdate( 'Y-m-d H:i:s' );
		}
		return time();
	}
}

// ----------------------------------------------------------------------
// Plugins / users / cron — best-effort no-ops for the modules under test
// ----------------------------------------------------------------------

if ( ! function_exists( 'get_plugins' ) ) {
	function get_plugins() {
		return array();
	}
}

if ( ! function_exists( 'is_plugin_active' ) ) {
	function is_plugin_active( $file ) {
		$active = (array) get_option( 'active_plugins', array() );
		return in_array( $file, $active, true );
	}
}

if ( ! function_exists( 'deactivate_plugins' ) ) {
	function deactivate_plugins( $plugins ) {
		// Not exercised by the snapshot test — keep as a no-op.
	}
}

if ( ! function_exists( 'activate_plugin' ) ) {
	function activate_plugin( $file, $redirect = '', $network_wide = false, $silent = false ) {
		return true;
	}
}

if ( ! function_exists( 'get_users' ) ) {
	function get_users( $args = array() ) {
		return array();
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = array() ) {
		return false;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return true;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		// no-op
	}
}

if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( $file, $callback ) {
		return true;
	}
}

if ( ! function_exists( 'is_ssl' ) ) {
	function is_ssl() {
		if ( isset( $_SERVER['HTTPS'] ) && 'on' === strtolower( (string) $_SERVER['HTTPS'] ) ) {
			return true;
		}
		return false;
	}
}

// ----------------------------------------------------------------------
// WP_Error — used by IAWM_Network and IAWM_Auth.
// ----------------------------------------------------------------------

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {

		/**
		 * Error code.
		 *
		 * @var string
		 */
		public $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		public $message;

		/**
		 * Optional data payload (typically holds `status`).
		 *
		 * @var array
		 */
		public $data;

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Human-readable message.
		 * @param array  $data    Optional data.
		 */
		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
			$this->data    = (array) $data;
		}

		/**
		 * Returns the error code.
		 *
		 * @return string
		 */
		public function get_error_code() {
			return $this->code;
		}

		/**
		 * Returns the error message.
		 *
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}

		/**
		 * Returns the data payload.
		 *
		 * @return array
		 */
		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

// ----------------------------------------------------------------------
// WP_REST_Response — used by IAWM_Confirmation::guard.
// ----------------------------------------------------------------------

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {

		/**
		 * Response body.
		 *
		 * @var mixed
		 */
		public $data;

		/**
		 * HTTP status code.
		 *
		 * @var int
		 */
		public $status;

		/**
		 * Constructor.
		 *
		 * @param mixed $data    Response body.
		 * @param int   $status  Status code.
		 * @param array $headers Headers (unused).
		 */
		public function __construct( $data = null, $status = 200, $headers = array() ) {
			$this->data   = $data;
			$this->status = (int) $status;
		}

		/**
		 * Returns the response body.
		 *
		 * @return mixed
		 */
		public function get_data() {
			return $this->data;
		}

		/**
		 * Returns the status code.
		 *
		 * @return int
		 */
		public function get_status() {
			return $this->status;
		}
	}
}

// ----------------------------------------------------------------------
// WP_REST_Request — fake-just-enough for the auth and confirmation
// tests. Production code uses the real one; the polyfill exposes the
// same methods the modules call.
// ----------------------------------------------------------------------

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {

		/**
		 * HTTP method.
		 *
		 * @var string
		 */
		private $method;

		/**
		 * Route, e.g. `/ia-webmaster/v1/status`.
		 *
		 * @var string
		 */
		private $route;

		/**
		 * Headers (case-insensitive map).
		 *
		 * @var array<string, string>
		 */
		private $headers = array();

		/**
		 * Query string parameters.
		 *
		 * @var array<string, mixed>
		 */
		private $query = array();

		/**
		 * Raw request body.
		 *
		 * @var string
		 */
		private $body = '';

		/**
		 * Decoded JSON body cache.
		 *
		 * @var array|null
		 */
		private $json_params = null;

		/**
		 * Constructor.
		 *
		 * @param string $method HTTP method.
		 * @param string $route  REST route.
		 */
		public function __construct( $method = 'POST', $route = '' ) {
			$this->method = strtoupper( (string) $method );
			$this->route  = (string) $route;
		}

		/**
		 * Sets a header (case-insensitive).
		 *
		 * @param string $name  Header name.
		 * @param string $value Value.
		 * @return void
		 */
		public function set_header( $name, $value ) {
			$this->headers[ strtolower( $name ) ] = (string) $value;
		}

		/**
		 * Returns a header value, or '' if missing.
		 *
		 * @param string $name Header name.
		 * @return string
		 */
		public function get_header( $name ) {
			$key = strtolower( $name );
			return isset( $this->headers[ $key ] ) ? $this->headers[ $key ] : '';
		}

		/**
		 * Sets the raw body and invalidates the JSON cache.
		 *
		 * @param string $body Body.
		 * @return void
		 */
		public function set_body( $body ) {
			$this->body        = (string) $body;
			$this->json_params = null;
		}

		/**
		 * Returns the raw body.
		 *
		 * @return string
		 */
		public function get_body() {
			return $this->body;
		}

		/**
		 * Returns the HTTP method.
		 *
		 * @return string
		 */
		public function get_method() {
			return $this->method;
		}

		/**
		 * Returns the REST route.
		 *
		 * @return string
		 */
		public function get_route() {
			return $this->route;
		}

		/**
		 * Sets the query parameters.
		 *
		 * @param array $params Map.
		 * @return void
		 */
		public function set_query_params( $params ) {
			$this->query = (array) $params;
		}

		/**
		 * Returns the query parameters.
		 *
		 * @return array
		 */
		public function get_query_params() {
			return $this->query;
		}

		/**
		 * Returns the decoded JSON body.
		 *
		 * @return array
		 */
		public function get_json_params() {
			if ( null !== $this->json_params ) {
				return $this->json_params;
			}
			if ( '' === $this->body ) {
				$this->json_params = array();
				return $this->json_params;
			}
			$decoded = json_decode( $this->body, true );
			$this->json_params = is_array( $decoded ) ? $decoded : array();
			return $this->json_params;
		}
	}
}

// ----------------------------------------------------------------------
// WP_REST_Server (only the CREATABLE constant is referenced)
// ----------------------------------------------------------------------

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		const READABLE  = 'GET';
		const CREATABLE = 'POST';
		const EDITABLE  = 'POST, PUT, PATCH';
		const DELETABLE = 'DELETE';
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $namespace, $route, $args = array(), $override = false ) {
		return true;
	}
}
