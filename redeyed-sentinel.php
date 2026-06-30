<?php
/**
 * Plugin Name:       Redeyed Sentinel
 * Plugin URI:        https://redeyed.com
 * Description:       Adds the Redeyed Sentinel CAPTCHA and IP-reputation check to your WordPress login, registration and comment forms. Free to install and completely inert until you enter your Sentinel keys.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Redeyed Corporation
 * Author URI:        https://redeyed.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       redeyed-sentinel
 *
 * @package RedeyedSentinel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

if ( ! class_exists( 'Redeyed_Sentinel' ) ) :

	/**
	 * Main Redeyed Sentinel plugin class.
	 */
	final class Redeyed_Sentinel {

		/**
		 * Plugin version.
		 */
		const VERSION = '1.0.0';

		/**
		 * Option name used to store all settings.
		 */
		const OPTION_KEY = 'redeyed_sentinel_options';

		/**
		 * Default base URL for the Sentinel service.
		 */
		const DEFAULT_BASE_URL = 'https://redeyed.com';

		/**
		 * Singleton instance.
		 *
		 * @var Redeyed_Sentinel|null
		 */
		private static $instance = null;

		/**
		 * Tracks whether the Sentinel script has already been printed on the page.
		 *
		 * @var bool
		 */
		private $script_printed = false;

		/**
		 * Get the singleton instance.
		 *
		 * @return Redeyed_Sentinel
		 */
		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Constructor: wires up all hooks.
		 */
		private function __construct() {
			// Admin settings.
			add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_action( 'admin_notices', array( $this, 'maybe_show_inactive_notice' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );

			// Render the widget on the enabled forms.
			add_action( 'login_enqueue_scripts', array( $this, 'enqueue_login_assets' ) );
			add_action( 'login_form', array( $this, 'render_login_widget' ) );
			add_action( 'register_form', array( $this, 'render_register_widget' ) );
			add_action( 'comment_form_after_fields', array( $this, 'render_comment_widget' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front_assets' ) );

			// Verify on submission.
			add_filter( 'authenticate', array( $this, 'verify_login' ), 30, 3 );
			add_filter( 'registration_errors', array( $this, 'verify_registration' ), 10, 3 );
			add_filter( 'preprocess_comment', array( $this, 'verify_comment' ) );
		}

		/* --------------------------------------------------------------------- *
		 * Options helpers
		 * --------------------------------------------------------------------- */

		/**
		 * Get the merged plugin options with defaults applied.
		 *
		 * @return array
		 */
		public function get_options() {
			$defaults = array(
				'site_key'           => '',
				'api_key'            => '',
				'base_url'           => self::DEFAULT_BASE_URL,
				'enable_login'       => 0,
				'enable_register'    => 0,
				'enable_comments'    => 0,
			);

			$options = get_option( self::OPTION_KEY, array() );
			if ( ! is_array( $options ) ) {
				$options = array();
			}

			return wp_parse_args( $options, $defaults );
		}

		/**
		 * Get the configured Site Key.
		 *
		 * @return string
		 */
		private function get_site_key() {
			return (string) $this->get_options()['site_key'];
		}

		/**
		 * Get the configured API Key.
		 *
		 * @return string
		 */
		private function get_api_key() {
			return (string) $this->get_options()['api_key'];
		}

		/**
		 * Get the configured Base URL (trailing slash trimmed).
		 *
		 * @return string
		 */
		private function get_base_url() {
			$url = trim( (string) $this->get_options()['base_url'] );
			if ( '' === $url ) {
				$url = self::DEFAULT_BASE_URL;
			}
			return untrailingslashit( $url );
		}

		/**
		 * Whether Sentinel has both keys and is therefore active.
		 *
		 * @return bool
		 */
		private function is_configured() {
			return '' !== $this->get_site_key() && '' !== $this->get_api_key();
		}

		/* --------------------------------------------------------------------- *
		 * Settings page
		 * --------------------------------------------------------------------- */

		/**
		 * Register the Settings → Sentinel page.
		 */
		public function add_settings_page() {
			add_options_page(
				__( 'Redeyed Sentinel', 'redeyed-sentinel' ),
				__( 'Sentinel', 'redeyed-sentinel' ),
				'manage_options',
				'redeyed-sentinel',
				array( $this, 'render_settings_page' )
			);
		}

		/**
		 * Register settings, sections and fields with sanitization.
		 */
		public function register_settings() {
			register_setting(
				'redeyed_sentinel_group',
				self::OPTION_KEY,
				array(
					'type'              => 'array',
					'sanitize_callback' => array( $this, 'sanitize_options' ),
					'default'           => array(),
				)
			);

			add_settings_section(
				'redeyed_sentinel_keys',
				__( 'Sentinel Keys', 'redeyed-sentinel' ),
				array( $this, 'render_keys_section_intro' ),
				'redeyed-sentinel'
			);

			add_settings_field(
				'site_key',
				__( 'Site Key', 'redeyed-sentinel' ),
				array( $this, 'render_site_key_field' ),
				'redeyed-sentinel',
				'redeyed_sentinel_keys'
			);

			add_settings_field(
				'api_key',
				__( 'API Key', 'redeyed-sentinel' ),
				array( $this, 'render_api_key_field' ),
				'redeyed-sentinel',
				'redeyed_sentinel_keys'
			);

			add_settings_field(
				'base_url',
				__( 'Base URL', 'redeyed-sentinel' ),
				array( $this, 'render_base_url_field' ),
				'redeyed-sentinel',
				'redeyed_sentinel_keys'
			);

			add_settings_section(
				'redeyed_sentinel_placement',
				__( 'Protected Forms', 'redeyed-sentinel' ),
				array( $this, 'render_placement_section_intro' ),
				'redeyed-sentinel'
			);

			add_settings_field(
				'enable_login',
				__( 'Login form', 'redeyed-sentinel' ),
				array( $this, 'render_enable_login_field' ),
				'redeyed-sentinel',
				'redeyed_sentinel_placement'
			);

			add_settings_field(
				'enable_register',
				__( 'Registration form', 'redeyed-sentinel' ),
				array( $this, 'render_enable_register_field' ),
				'redeyed-sentinel',
				'redeyed_sentinel_placement'
			);

			add_settings_field(
				'enable_comments',
				__( 'Comment form', 'redeyed-sentinel' ),
				array( $this, 'render_enable_comments_field' ),
				'redeyed-sentinel',
				'redeyed_sentinel_placement'
			);
		}

		/**
		 * Sanitize all options before they are stored.
		 *
		 * @param array $input Raw input from the settings form.
		 * @return array
		 */
		public function sanitize_options( $input ) {
			$input    = is_array( $input ) ? $input : array();
			$existing = $this->get_options();

			$base_url = isset( $input['base_url'] ) ? esc_url_raw( trim( (string) $input['base_url'] ) ) : '';
			if ( '' === $base_url ) {
				$base_url = self::DEFAULT_BASE_URL;
			}

			$clean = array(
				'site_key'        => isset( $input['site_key'] ) ? sanitize_text_field( $input['site_key'] ) : '',
				'api_key'         => isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : $existing['api_key'],
				'base_url'        => $base_url,
				'enable_login'    => empty( $input['enable_login'] ) ? 0 : 1,
				'enable_register' => empty( $input['enable_register'] ) ? 0 : 1,
				'enable_comments' => empty( $input['enable_comments'] ) ? 0 : 1,
			);

			return $clean;
		}

		/**
		 * Intro copy for the keys section.
		 */
		public function render_keys_section_intro() {
			echo '<p>' . wp_kses_post(
				__( 'Sentinel is free to install and does nothing until both keys are entered. Grab your <strong>Site Key</strong> from the Redeyed Lab → Developer → Sentinel Sites and your <strong>API Key</strong> from Developer → API Keys.', 'redeyed-sentinel' )
			) . '</p>';
		}

		/**
		 * Intro copy for the placement section.
		 */
		public function render_placement_section_intro() {
			echo '<p>' . esc_html__( 'Choose which forms should display and verify the Sentinel CAPTCHA.', 'redeyed-sentinel' ) . '</p>';
		}

		/**
		 * Render the Site Key field.
		 */
		public function render_site_key_field() {
			$options = $this->get_options();
			printf(
				'<input type="text" class="regular-text" id="redeyed_sentinel_site_key" name="%1$s[site_key]" value="%2$s" autocomplete="off" />',
				esc_attr( self::OPTION_KEY ),
				esc_attr( $options['site_key'] )
			);
			echo '<p class="description">' . esc_html__( 'Public key that renders the widget. Safe to expose in page markup.', 'redeyed-sentinel' ) . '</p>';
		}

		/**
		 * Render the API Key field. Never prints the stored secret.
		 */
		public function render_api_key_field() {
			$has_key = '' !== $this->get_api_key();
			printf(
				'<input type="password" class="regular-text" id="redeyed_sentinel_api_key" name="%1$s[api_key]" value="" autocomplete="new-password" placeholder="%2$s" />',
				esc_attr( self::OPTION_KEY ),
				esc_attr( $has_key ? __( 'A key is saved — leave blank to keep it', 'redeyed-sentinel' ) : __( 'Enter your secret API key', 'redeyed-sentinel' ) )
			);
			echo '<p class="description">' . esc_html__( 'Secret key, sent only server-side as the X-Api-Key header. It is never printed back here.', 'redeyed-sentinel' ) . '</p>';
		}

		/**
		 * Render the Base URL field.
		 */
		public function render_base_url_field() {
			$options = $this->get_options();
			printf(
				'<input type="url" class="regular-text" id="redeyed_sentinel_base_url" name="%1$s[base_url]" value="%2$s" placeholder="%3$s" />',
				esc_attr( self::OPTION_KEY ),
				esc_attr( $options['base_url'] ),
				esc_attr( self::DEFAULT_BASE_URL )
			);
			echo '<p class="description">' . sprintf(
				/* translators: %s: default base URL */
				esc_html__( 'Defaults to %s. Only change this for self-hosted Sentinel deployments.', 'redeyed-sentinel' ),
				'<code>' . esc_html( self::DEFAULT_BASE_URL ) . '</code>'
			) . '</p>';
		}

		/**
		 * Render the "enable on login" checkbox.
		 */
		public function render_enable_login_field() {
			$this->render_checkbox( 'enable_login', __( 'Protect the WordPress login form.', 'redeyed-sentinel' ) );
		}

		/**
		 * Render the "enable on registration" checkbox.
		 */
		public function render_enable_register_field() {
			$this->render_checkbox( 'enable_register', __( 'Protect the user registration form.', 'redeyed-sentinel' ) );
		}

		/**
		 * Render the "enable on comments" checkbox.
		 */
		public function render_enable_comments_field() {
			$this->render_checkbox( 'enable_comments', __( 'Protect the comment form.', 'redeyed-sentinel' ) );
		}

		/**
		 * Helper to render a single checkbox field.
		 *
		 * @param string $key   Option key.
		 * @param string $label Label text.
		 */
		private function render_checkbox( $key, $label ) {
			$options = $this->get_options();
			printf(
				'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
				esc_attr( self::OPTION_KEY ),
				esc_attr( $key ),
				checked( ! empty( $options[ $key ] ), true, false ),
				esc_html( $label )
			);
		}

		/**
		 * Render the settings page wrapper.
		 */
		public function render_settings_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			?>
			<div class="wrap">
				<h1><?php echo esc_html__( 'Redeyed Sentinel', 'redeyed-sentinel' ); ?></h1>

				<?php if ( ! $this->is_configured() ) : ?>
					<div class="notice notice-info inline">
						<p><?php esc_html_e( 'Sentinel is currently inactive. Enter both a Site Key and an API Key to start protecting your forms.', 'redeyed-sentinel' ); ?></p>
					</div>
				<?php else : ?>
					<div class="notice notice-success inline">
						<p><?php esc_html_e( 'Sentinel is configured and active on your selected forms.', 'redeyed-sentinel' ); ?></p>
					</div>
				<?php endif; ?>

				<form action="options.php" method="post">
					<?php
					settings_fields( 'redeyed_sentinel_group' );
					do_settings_sections( 'redeyed-sentinel' );
					submit_button();
					?>
				</form>
			</div>
			<?php
		}

		/**
		 * Add a quick "Settings" link on the plugins screen.
		 *
		 * @param array $links Existing action links.
		 * @return array
		 */
		public function plugin_action_links( $links ) {
			$settings_link = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'options-general.php?page=redeyed-sentinel' ) ),
				esc_html__( 'Settings', 'redeyed-sentinel' )
			);
			array_unshift( $links, $settings_link );
			return $links;
		}

		/**
		 * Show an admin notice when forms are enabled but keys are missing.
		 */
		public function maybe_show_inactive_notice() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$options       = $this->get_options();
			$any_enabled   = ! empty( $options['enable_login'] ) || ! empty( $options['enable_register'] ) || ! empty( $options['enable_comments'] );

			if ( $any_enabled && ! $this->is_configured() ) {
				$url = admin_url( 'options-general.php?page=redeyed-sentinel' );
				echo '<div class="notice notice-warning"><p>';
				printf(
					/* translators: %s: settings page URL */
					wp_kses_post( __( 'Redeyed Sentinel is enabled on one or more forms but is <strong>inactive</strong> because its keys are not set. Forms are not being verified. <a href="%s">Add your Sentinel keys</a>.', 'redeyed-sentinel' ) ),
					esc_url( $url )
				);
				echo '</p></div>';
			}
		}

		/* --------------------------------------------------------------------- *
		 * Rendering the widget
		 * --------------------------------------------------------------------- */

		/**
		 * Enqueue the Sentinel script on the login page when login or register is enabled.
		 */
		public function enqueue_login_assets() {
			$options = $this->get_options();
			if ( $this->is_configured() && ( ! empty( $options['enable_login'] ) || ! empty( $options['enable_register'] ) ) ) {
				$this->enqueue_script();
			}
		}

		/**
		 * Enqueue the Sentinel script on the front end when comments are enabled.
		 */
		public function enqueue_front_assets() {
			$options = $this->get_options();
			if ( $this->is_configured() && ! empty( $options['enable_comments'] ) && ( is_singular() && comments_open() ) ) {
				$this->enqueue_script();
			}
		}

		/**
		 * Register and enqueue the async Sentinel loader script (once).
		 */
		private function enqueue_script() {
			if ( $this->script_printed ) {
				return;
			}
			$handle = 'redeyed-sentinel';
			$src    = $this->get_base_url() . '/sentinel.js';

			wp_register_script( $handle, esc_url_raw( $src ), array(), self::VERSION, true );
			wp_enqueue_script( $handle );

			// Ensure the script loads with the async attribute per the integration contract.
			add_filter(
				'script_loader_tag',
				static function ( $tag, $tag_handle ) use ( $handle ) {
					if ( $tag_handle === $handle && false === strpos( $tag, ' async' ) ) {
						$tag = str_replace( '<script ', '<script async ', $tag );
					}
					return $tag;
				},
				10,
				2
			);

			$this->script_printed = true;
		}

		/**
		 * Output the widget markup.
		 */
		private function render_widget() {
			if ( ! $this->is_configured() ) {
				return;
			}
			printf(
				'<div class="sentinel-captcha" data-sitekey="%s"></div>',
				esc_attr( $this->get_site_key() )
			);
		}

		/**
		 * Render widget inside the login form.
		 */
		public function render_login_widget() {
			$options = $this->get_options();
			if ( ! empty( $options['enable_login'] ) ) {
				// On the login page the script is enqueued via login_enqueue_scripts; ensure it is present.
				$this->ensure_inline_script();
				$this->render_widget();
			}
		}

		/**
		 * Render widget inside the registration form.
		 */
		public function render_register_widget() {
			$options = $this->get_options();
			if ( ! empty( $options['enable_register'] ) ) {
				$this->ensure_inline_script();
				$this->render_widget();
			}
		}

		/**
		 * Render widget inside the comment form.
		 */
		public function render_comment_widget() {
			$options = $this->get_options();
			if ( ! empty( $options['enable_comments'] ) ) {
				$this->ensure_inline_script();
				$this->render_widget();
			}
		}

		/**
		 * As a safety net for contexts where wp_enqueue may not run (e.g. some login
		 * themes), print the loader script directly once if it has not been enqueued.
		 */
		private function ensure_inline_script() {
			if ( $this->script_printed ) {
				return;
			}
			if ( ! $this->is_configured() ) {
				return;
			}
			if ( wp_script_is( 'redeyed-sentinel', 'enqueued' ) || wp_script_is( 'redeyed-sentinel', 'done' ) ) {
				$this->script_printed = true;
				return;
			}
			printf(
				'<script src="%s" async></script>',
				esc_url( $this->get_base_url() . '/sentinel.js' )
			);
			$this->script_printed = true;
		}

		/* --------------------------------------------------------------------- *
		 * Verification
		 * --------------------------------------------------------------------- */

		/**
		 * Read the submitted Sentinel token from the request.
		 *
		 * @return string
		 */
		private function get_submitted_token() {
			if ( ! isset( $_POST['sentinel-token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				return '';
			}
			return sanitize_text_field( wp_unslash( $_POST['sentinel-token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		/**
		 * Verify a token against the Sentinel API.
		 *
		 * Fails OPEN (returns true) when keys are not configured.
		 *
		 * @param string $token Submitted token.
		 * @return bool True when verification passed (or fail-open), false otherwise.
		 */
		private function verify_token( $token ) {
			// Fail open when not configured — never block a site without keys.
			if ( ! $this->is_configured() ) {
				return true;
			}

			if ( '' === $token ) {
				return false;
			}

			$endpoint = $this->get_base_url() . '/api/v1/verify';

			$response = wp_remote_post(
				$endpoint,
				array(
					'timeout' => 10,
					'headers' => array(
						'X-Api-Key'    => $this->get_api_key(),
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					),
					'body'    => wp_json_encode(
						array(
							'site_key' => $this->get_site_key(),
							'token'    => $token,
						)
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				return false;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( ! is_array( $data ) ) {
				return false;
			}

			// Handle both response shapes: nested data.success and top-level success.
			if ( isset( $data['data']['success'] ) && true === $data['data']['success'] ) {
				return true;
			}
			if ( isset( $data['success'] ) && true === $data['success'] ) {
				return true;
			}

			return false;
		}

		/**
		 * Verify the login form (authenticate filter).
		 *
		 * @param WP_User|WP_Error|null $user     User or error from earlier filters.
		 * @param string                $username Submitted username.
		 * @param string                $password Submitted password.
		 * @return WP_User|WP_Error
		 */
		public function verify_login( $user, $username, $password ) {
			$options = $this->get_options();

			if ( empty( $options['enable_login'] ) || ! $this->is_configured() ) {
				return $user;
			}

			// Only verify on an actual POSTed login attempt with credentials.
			if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
				return $user;
			}
			if ( empty( $username ) && empty( $password ) ) {
				return $user;
			}

			if ( ! $this->verify_token( $this->get_submitted_token() ) ) {
				return new WP_Error(
					'redeyed_sentinel_failed',
					__( '<strong>Sentinel:</strong> CAPTCHA verification failed. Please try again.', 'redeyed-sentinel' )
				);
			}

			return $user;
		}

		/**
		 * Verify the registration form (registration_errors filter).
		 *
		 * @param WP_Error $errors               Existing registration errors.
		 * @param string   $sanitized_user_login User login.
		 * @param string   $user_email           User email.
		 * @return WP_Error
		 */
		public function verify_registration( $errors, $sanitized_user_login, $user_email ) {
			$options = $this->get_options();

			if ( empty( $options['enable_register'] ) || ! $this->is_configured() ) {
				return $errors;
			}

			if ( ! $this->verify_token( $this->get_submitted_token() ) ) {
				$errors->add(
					'redeyed_sentinel_failed',
					__( '<strong>Sentinel:</strong> CAPTCHA verification failed. Please try again.', 'redeyed-sentinel' )
				);
			}

			return $errors;
		}

		/**
		 * Verify the comment form (preprocess_comment).
		 *
		 * @param array $commentdata Comment data.
		 * @return array
		 */
		public function verify_comment( $commentdata ) {
			$options = $this->get_options();

			if ( empty( $options['enable_comments'] ) || ! $this->is_configured() ) {
				return $commentdata;
			}

			// Skip verification for logged-in users performing trusted actions? Keep strict by default.
			if ( ! $this->verify_token( $this->get_submitted_token() ) ) {
				wp_die(
					esc_html__( 'Sentinel: CAPTCHA verification failed. Please go back and try again.', 'redeyed-sentinel' ),
					esc_html__( 'Comment Blocked', 'redeyed-sentinel' ),
					array(
						'response'  => 403,
						'back_link' => true,
					)
				);
			}

			return $commentdata;
		}
	}

endif;

/**
 * Boot the plugin.
 */
function redeyed_sentinel() {
	return Redeyed_Sentinel::instance();
}
add_action( 'plugins_loaded', 'redeyed_sentinel' );
