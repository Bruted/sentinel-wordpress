<?php
/**
 * Plugin Name:       Redeyed Sentinel
 * Plugin URI:        https://redeyed.com/sentinel
 * Description:       Adds the Redeyed Sentinel CAPTCHA and IP-reputation check to your WordPress login, registration, lost-password and comment forms, with an admin block log. Free to install and completely inert until you enter your Sentinel keys.
 * Version:           1.0.5
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
		const VERSION = '1.0.5';

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
			add_action( 'admin_menu', array( $this, 'add_log_page' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_action( 'admin_init', array( $this, 'maybe_handle_log_actions' ) );
			add_action( 'admin_notices', array( $this, 'maybe_show_inactive_notice' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );

			// Render the widget on the enabled forms.
			add_action( 'login_enqueue_scripts', array( $this, 'enqueue_login_assets' ) );
			add_action( 'login_form', array( $this, 'render_login_widget' ) );
			add_action( 'register_form', array( $this, 'render_register_widget' ) );
			add_action( 'lostpassword_form', array( $this, 'render_lostpassword_widget' ) );
			add_action( 'comment_form_after_fields', array( $this, 'render_comment_widget' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front_assets' ) );

			// Verify on submission.
			add_filter( 'authenticate', array( $this, 'verify_login' ), 30, 3 );
			add_filter( 'registration_errors', array( $this, 'verify_registration' ), 10, 3 );
			add_action( 'lostpassword_post', array( $this, 'verify_lostpassword' ), 10, 1 );
			add_filter( 'preprocess_comment', array( $this, 'verify_comment' ) );
		}

		/* --------------------------------------------------------------------- *
		 * Activation / block-log table
		 * --------------------------------------------------------------------- */

		/**
		 * Fully-qualified block-log table name.
		 *
		 * @return string
		 */
		public static function log_table() {
			global $wpdb;
			return $wpdb->prefix . 'redeyed_sentinel_log';
		}

		/**
		 * Activation hook: create the block-log table.
		 */
		public static function on_activate() {
			global $wpdb;

			$table           = self::log_table();
			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				form VARCHAR(20) NOT NULL DEFAULT '',
				ip VARCHAR(45) NOT NULL DEFAULT '',
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				subject VARCHAR(190) NOT NULL DEFAULT '',
				outcome VARCHAR(40) NOT NULL DEFAULT '',
				score FLOAT NULL,
				created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY form (form)
			) {$charset_collate};";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );
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
				'secret_key'            => '',
				'base_url'           => self::DEFAULT_BASE_URL,
				'enable_login'       => 0,
				'enable_register'    => 0,
				'enable_lostpassword' => 0,
				'enable_comments'    => 0,
				'enable_logging'     => 1,
				'widget'             => '',
				'theme'              => '',
				'scheme'             => '',
				'difficulty'         => '',
				'width'              => '',
				'form'               => '',
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
		 * Get the configured Secret Key.
		 *
		 * @return string
		 */
		private function get_secret_key() {
			return (string) $this->get_options()['secret_key'];
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
			return '' !== $this->get_site_key() && '' !== $this->get_secret_key();
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
				'secret_key',
				__( 'Secret Key', 'redeyed-sentinel' ),
				array( $this, 'render_secret_key_field' ),
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
				'enable_lostpassword',
				__( 'Lost password form', 'redeyed-sentinel' ),
				array( $this, 'render_enable_lostpassword_field' ),
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

			add_settings_field(
				'enable_logging',
				__( 'Block log', 'redeyed-sentinel' ),
				array( $this, 'render_enable_logging_field' ),
				'redeyed-sentinel',
				'redeyed_sentinel_placement'
			);

			add_settings_section(
				'redeyed_sentinel_appearance',
				__( 'Widget Customization', 'redeyed-sentinel' ),
				array( $this, 'render_appearance_section_intro' ),
				'redeyed-sentinel'
			);

			add_settings_field(
				'widget',
				__( 'Widget type', 'redeyed-sentinel' ),
				array( $this, 'render_widget_field' ),
				'redeyed-sentinel',
				'redeyed_sentinel_appearance'
			);

			add_settings_field(
				'theme',
				__( 'Theme', 'redeyed-sentinel' ),
				array( $this, 'render_theme_field' ),
				'redeyed-sentinel',
				'redeyed_sentinel_appearance'
			);

			add_settings_field(
				'scheme',
				__( 'Colour scheme', 'redeyed-sentinel' ),
				array( $this, 'render_scheme_field' ),
				'redeyed-sentinel',
				'redeyed_sentinel_appearance'
			);

			add_settings_field(
				'difficulty',
				__( 'Difficulty', 'redeyed-sentinel' ),
				array( $this, 'render_difficulty_field' ),
				'redeyed-sentinel',
				'redeyed_sentinel_appearance'
			);

			add_settings_field(
				'width',
				__( 'Width', 'redeyed-sentinel' ),
				array( $this, 'render_width_field' ),
				'redeyed-sentinel',
				'redeyed_sentinel_appearance'
			);

			add_settings_field(
				'form',
				__( 'Form key', 'redeyed-sentinel' ),
				array( $this, 'render_form_field' ),
				'redeyed-sentinel',
				'redeyed_sentinel_appearance'
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
				'secret_key'         => isset( $input['secret_key'] ) ? sanitize_text_field( $input['secret_key'] ) : $existing['secret_key'],
				'base_url'        => $base_url,
				'enable_login'    => empty( $input['enable_login'] ) ? 0 : 1,
				'enable_register' => empty( $input['enable_register'] ) ? 0 : 1,
				'enable_lostpassword' => empty( $input['enable_lostpassword'] ) ? 0 : 1,
				'enable_comments' => empty( $input['enable_comments'] ) ? 0 : 1,
				'enable_logging'  => empty( $input['enable_logging'] ) ? 0 : 1,
				'widget'          => isset( $input['widget'] ) ? sanitize_text_field( $input['widget'] ) : '',
				'theme'           => isset( $input['theme'] ) ? sanitize_text_field( $input['theme'] ) : '',
				'scheme'          => isset( $input['scheme'] ) ? sanitize_text_field( $input['scheme'] ) : '',
				'difficulty'      => isset( $input['difficulty'] ) ? sanitize_text_field( $input['difficulty'] ) : '',
				'width'           => isset( $input['width'] ) ? sanitize_text_field( $input['width'] ) : '',
				'form'            => isset( $input['form'] ) ? sanitize_text_field( $input['form'] ) : '',
			);

			return $clean;
		}

		/**
		 * Intro copy for the keys section.
		 */
		public function render_keys_section_intro() {
			echo '<p>' . wp_kses_post(
				__( 'Sentinel is free to install and does nothing until both keys are entered. Grab your <strong>Site Key</strong> and <strong>Secret Key</strong> from the Redeyed Lab → Sentinel → Sites (the Secret Key is shown once when you create the site).', 'redeyed-sentinel' )
			) . '</p>';
		}

		/**
		 * Intro copy for the placement section.
		 */
		public function render_placement_section_intro() {
			echo '<p>' . esc_html__( 'Choose which forms should display and verify the Sentinel CAPTCHA.', 'redeyed-sentinel' ) . '</p>';
		}

		/**
		 * Intro copy for the widget customization section.
		 */
		public function render_appearance_section_intro() {
			echo '<p>' . esc_html__( 'Optional site-wide defaults for how the Sentinel widget looks and behaves. Every field is optional — leave any blank to use the Sentinel default. These render as data-* attributes on the widget.', 'redeyed-sentinel' ) . '</p>';
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
		public function render_secret_key_field() {
			$has_key = '' !== $this->get_secret_key();
			printf(
				'<input type="password" class="regular-text" id="redeyed_sentinel_secret_key" name="%1$s[secret_key]" value="" autocomplete="new-password" placeholder="%2$s" />',
				esc_attr( self::OPTION_KEY ),
				esc_attr( $has_key ? __( 'A key is saved — leave blank to keep it', 'redeyed-sentinel' ) : __( 'Enter your Secret Key', 'redeyed-sentinel' ) )
			);
			echo '<p class="description">' . esc_html__( 'Secret key for this site, sent only server-side to /sentinel/siteverify. It is never printed back here.', 'redeyed-sentinel' ) . '</p>';
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
		 * Render the "enable on lost password" checkbox.
		 */
		public function render_enable_lostpassword_field() {
			$this->render_checkbox( 'enable_lostpassword', __( 'Protect the lost-password (password reset) form.', 'redeyed-sentinel' ) );
		}

		/**
		 * Render the "enable on comments" checkbox.
		 */
		public function render_enable_comments_field() {
			$this->render_checkbox( 'enable_comments', __( 'Protect the comment form.', 'redeyed-sentinel' ) );
		}

		/**
		 * Render the "block log" checkbox.
		 */
		public function render_enable_logging_field() {
			$this->render_checkbox( 'enable_logging', __( 'Record blocked attempts (form, IP, outcome, score) to the Sentinel block log.', 'redeyed-sentinel' ) );
			$url = admin_url( 'options-general.php?page=redeyed-sentinel-log' );
			echo '<p class="description"><a href="' . esc_url( $url ) . '">' . esc_html__( 'View the block log →', 'redeyed-sentinel' ) . '</a></p>';
		}

		/**
		 * Render the Widget type field.
		 */
		public function render_widget_field() {
			$this->render_text_setting(
				'widget',
				'behavioral',
				__( 'Widget style, e.g. <code>behavioral</code>, <code>checkbox</code>, <code>press_hold</code> or <code>image_pick</code>. Leave blank for the Sentinel default.', 'redeyed-sentinel' )
			);
		}

		/**
		 * Render the Theme field.
		 */
		public function render_theme_field() {
			$this->render_text_setting(
				'theme',
				'auto',
				__( 'Widget theme: <code>auto</code>, <code>light</code> or <code>dark</code>. Leave blank for the Sentinel default.', 'redeyed-sentinel' )
			);
		}

		/**
		 * Render the Colour scheme field.
		 */
		public function render_scheme_field() {
			$this->render_text_setting(
				'scheme',
				'',
				__( 'Optional colour scheme name for the widget. Leave blank for the Sentinel default.', 'redeyed-sentinel' )
			);
		}

		/**
		 * Render the Difficulty field.
		 */
		public function render_difficulty_field() {
			$this->render_text_setting(
				'difficulty',
				'medium',
				__( 'Minimum challenge strength: <code>easy</code>, <code>medium</code>, <code>hard</code>, <code>max</code> (or <code>1</code>–<code>6</code>). This only <strong>raises</strong> difficulty above the adaptive baseline — a risky visitor is always challenged hard regardless. Leave blank for the Sentinel default.', 'redeyed-sentinel' )
			);
		}

		/**
		 * Render the Width field.
		 */
		public function render_width_field() {
			$this->render_text_setting(
				'width',
				'full',
				__( 'Optional widget width, e.g. <code>full</code>, <code>100%</code> or <code>340px</code>. Leave blank for the Sentinel default.', 'redeyed-sentinel' )
			);
		}

		/**
		 * Render the Form key field.
		 */
		public function render_form_field() {
			$this->render_text_setting(
				'form',
				'',
				__( 'Optional form identifier passed to the widget as <code>data-form</code>. Leave blank for the Sentinel default.', 'redeyed-sentinel' )
			);
		}

		/**
		 * Helper to render a single optional text setting field.
		 *
		 * @param string $key         Option key.
		 * @param string $placeholder Placeholder text.
		 * @param string $description Description markup (a small set of HTML tags allowed).
		 */
		private function render_text_setting( $key, $placeholder, $description ) {
			$options = $this->get_options();
			printf(
				'<input type="text" class="regular-text" id="redeyed_sentinel_%1$s" name="%2$s[%1$s]" value="%3$s" autocomplete="off" placeholder="%4$s" />',
				esc_attr( $key ),
				esc_attr( self::OPTION_KEY ),
				esc_attr( $options[ $key ] ),
				esc_attr( $placeholder )
			);
			echo '<p class="description">' . wp_kses_post( $description ) . '</p>';
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
						<p><?php esc_html_e( 'Sentinel is currently inactive. Enter both a Site Key and a Secret Key to start protecting your forms.', 'redeyed-sentinel' ); ?></p>
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
		 * Block log (admin visibility)
		 * --------------------------------------------------------------------- */

		/**
		 * Register the hidden "Sentinel Log" settings submenu page.
		 */
		public function add_log_page() {
			add_submenu_page(
				'options-general.php',
				__( 'Sentinel Block Log', 'redeyed-sentinel' ),
				__( 'Sentinel Log', 'redeyed-sentinel' ),
				'manage_options',
				'redeyed-sentinel-log',
				array( $this, 'render_log_page' )
			);
		}

		/**
		 * Handle the "clear log" action (nonce + capability protected).
		 */
		public function maybe_handle_log_actions() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
			$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
			if ( 'redeyed-sentinel-log' !== $page || 'clear' !== $action ) {
				return;
			}

			check_admin_referer( 'redeyed_sentinel_clear_log' );

			global $wpdb;
			$table = self::log_table();
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) { // phpcs:ignore WordPress.DB
				$wpdb->query( "TRUNCATE TABLE `{$table}`" ); // phpcs:ignore WordPress.DB
			}

			wp_safe_redirect( admin_url( 'options-general.php?page=redeyed-sentinel-log&cleared=1' ) );
			exit;
		}

		/**
		 * Render the block-log viewer.
		 */
		public function render_log_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			global $wpdb;
			$table  = self::log_table();
			$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ); // phpcs:ignore WordPress.DB

			$per_page = 30;
			$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$offset   = ( $paged - 1 ) * $per_page;

			$total = 0;
			$rows  = array();
			if ( $exists ) {
				$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB
				$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset ) ); // phpcs:ignore WordPress.DB
			}
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Redeyed Sentinel — Block Log', 'redeyed-sentinel' ); ?></h1>

				<?php if ( isset( $_GET['cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The block log has been cleared.', 'redeyed-sentinel' ); ?></p></div>
				<?php endif; ?>

				<?php if ( ! $exists ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'The log table does not exist yet. Deactivate and reactivate the plugin to create it.', 'redeyed-sentinel' ); ?></p></div>
				<?php else : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: number of blocked attempts */
							esc_html__( 'Submissions blocked by Sentinel — the form, source IP, outcome and score. %s total.', 'redeyed-sentinel' ),
							'<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>'
						);
						?>
					</p>

					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Time', 'redeyed-sentinel' ); ?></th>
								<th><?php esc_html_e( 'Form', 'redeyed-sentinel' ); ?></th>
								<th><?php esc_html_e( 'IP address', 'redeyed-sentinel' ); ?></th>
								<th><?php esc_html_e( 'Identity', 'redeyed-sentinel' ); ?></th>
								<th><?php esc_html_e( 'Outcome', 'redeyed-sentinel' ); ?></th>
								<th><?php esc_html_e( 'Score', 'redeyed-sentinel' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $rows ) ) : ?>
								<tr><td colspan="6"><?php esc_html_e( 'No blocked attempts recorded yet.', 'redeyed-sentinel' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $rows as $row ) : ?>
									<tr>
										<td><?php echo esc_html( $row->created_at ); ?></td>
										<td><?php echo esc_html( $row->form ); ?></td>
										<td><?php echo esc_html( $row->ip ); ?></td>
										<td><?php echo '' !== $row->subject ? esc_html( $row->subject ) : '&mdash;'; ?></td>
										<td><?php echo esc_html( $row->outcome ); ?></td>
										<td><?php echo ( null === $row->score ) ? '&mdash;' : esc_html( $row->score ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>

					<?php
					$total_pages = (int) ceil( $total / $per_page );
					if ( $total_pages > 1 ) {
						echo '<div class="tablenav"><div class="tablenav-pages">';
						echo wp_kses_post(
							paginate_links(
								array(
									'base'    => admin_url( 'options-general.php?page=redeyed-sentinel-log&paged=%#%' ),
									'format'  => '',
									'current' => $paged,
									'total'   => $total_pages,
								)
							)
						);
						echo '</div></div>';
					}
					?>

					<?php if ( $total > 0 ) : ?>
						<p style="margin-top:1em;">
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=redeyed-sentinel-log&action=clear' ), 'redeyed_sentinel_clear_log' ) ); ?>"
							   class="button"
							   onclick="return confirm('<?php echo esc_js( __( 'Clear the entire Sentinel block log?', 'redeyed-sentinel' ) ); ?>');">
								<?php esc_html_e( 'Clear log', 'redeyed-sentinel' ); ?>
							</a>
						</p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
			<?php
		}

		/* --------------------------------------------------------------------- *
		 * Rendering the widget
		 * --------------------------------------------------------------------- */

		/**
		 * Enqueue the Sentinel script on the login page when login or register is enabled.
		 */
		public function enqueue_login_assets() {
			$options = $this->get_options();
			if ( $this->is_configured() && ( ! empty( $options['enable_login'] ) || ! empty( $options['enable_register'] ) || ! empty( $options['enable_lostpassword'] ) ) ) {
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
		 *
		 * Renders each optional customization as a data-* attribute, but only when
		 * the value is non-empty so unconfigured widgets keep their original markup.
		 */
		private function render_widget() {
			if ( ! $this->is_configured() ) {
				return;
			}

			$options = $this->get_options();

			// Map each option to the data-* attribute it renders as.
			$customizations = array(
				'widget'     => 'data-widget',
				'theme'      => 'data-theme',
				'scheme'     => 'data-scheme',
				'difficulty' => 'data-difficulty',
				'width'      => 'data-width',
				'form'       => 'data-form',
			);

			// Output each piece through an escaping function AT the point of
			// output so WordPress.Security.EscapeOutput is satisfied (a
			// pre-built string variable trips the sniff even when its parts are
			// individually escaped).
			echo '<div class="sentinel-captcha" data-sitekey="' . esc_attr( $this->get_site_key() ) . '"';
			foreach ( $customizations as $option_key => $data_attr ) {
				$value = isset( $options[ $option_key ] ) ? trim( (string) $options[ $option_key ] ) : '';
				if ( '' !== $value ) {
					echo ' ' . esc_attr( $data_attr ) . '="' . esc_attr( $value ) . '"';
				}
			}
			echo '></div>';
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
		 * Render widget inside the lost-password form.
		 */
		public function render_lostpassword_widget() {
			$options = $this->get_options();
			if ( ! empty( $options['enable_lostpassword'] ) ) {
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
			if ( wp_script_is( 'redeyed-sentinel', 'done' ) ) {
				$this->script_printed = true;
				return;
			}
			$this->enqueue_script();
			if ( ! wp_script_is( 'redeyed-sentinel', 'done' ) ) {
				wp_scripts()->do_item( 'redeyed-sentinel' );
			}
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
		 * The visitor's real IP — proxy/CDN aware — so verify matches the solve IP.
		 *
		 * @return string
		 */
		private function get_client_ip() {
			$keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
			foreach ( $keys as $key ) {
				if ( empty( $_SERVER[ $key ] ) ) {
					continue;
				}
				$raw   = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				$parts = explode( ',', $raw ); // X-Forwarded-For: first entry is the client.
				$ip    = trim( $parts[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
			return '';
		}

		/**
		 * Verify a token against the Sentinel API.
		 *
		 * Fails OPEN (returns true) when keys are not configured.
		 *
		 * @param string $token Submitted token.
		 * @return bool True when verification passed (or fail-open), false otherwise.
		 */
		/**
		 * Verify a token and return the full result (passed/outcome/score).
		 *
		 * @param string $token Submitted token.
		 * @return array{passed: bool, outcome: string, score: float|null}
		 */
		private function verify_token_result( $token ) {
			$fail = array( 'passed' => false, 'outcome' => 'error', 'score' => null );

			// Fail open when not configured — never block a site without keys.
			if ( ! $this->is_configured() ) {
				return array( 'passed' => true, 'outcome' => 'skipped_no_secret', 'score' => null );
			}

			if ( '' === $token ) {
				return $fail;
			}

			$endpoint = $this->get_base_url() . '/sentinel/siteverify';

			// Send the visitor's real IP so verification matches the IP that
			// solved the challenge. WITHOUT this, Sentinel falls back to THIS
			// server's IP (a server-to-server call), the token never matches, and
			// the captcha "verifies" but the form still rejects it.
			$body = array(
				'secret'   => $this->get_secret_key(),
				'response' => $token,
			);
			$remote_ip = $this->get_client_ip();
			if ( '' !== $remote_ip ) {
				$body['remoteip'] = $remote_ip;
			}

			$response = wp_remote_post(
				$endpoint,
				array(
					'timeout' => 10,
					'headers' => array(
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					),
					'body'    => wp_json_encode( $body ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return $fail;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				return $fail;
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! is_array( $data ) ) {
				return $fail;
			}

			// Support both response shapes: nested data.* and top-level.
			$payload = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : $data;
			$outcome = isset( $payload['outcome'] ) ? (string) $payload['outcome'] : '';
			$score   = isset( $payload['score'] ) ? (float) $payload['score'] : null;
			$passed  = isset( $payload['success'] ) && true === $payload['success'];

			return array( 'passed' => $passed, 'outcome' => $outcome, 'score' => $score );
		}

		/**
		 * Verify the submitted token for a form; log a block on failure.
		 *
		 * @param string $form    login|register|lostpassword|comment.
		 * @param string $subject Attempted username/email for the log.
		 * @return bool Whether verification passed.
		 */
		private function guard( $form, $subject = '' ) {
			$result = $this->verify_token_result( $this->get_submitted_token() );

			if ( ! $result['passed'] ) {
				$options = $this->get_options();
				if ( ! empty( $options['enable_logging'] ) ) {
					$this->log_block( $form, $result['outcome'], $result['score'], $subject );
				}
			}

			return (bool) $result['passed'];
		}

		/**
		 * Record a blocked attempt to the log table. Best-effort — never fatals.
		 *
		 * @param string     $form
		 * @param string     $outcome
		 * @param float|null $score
		 * @param string     $subject
		 */
		private function log_block( $form, $outcome, $score, $subject = '' ) {
			global $wpdb;

			$table = self::log_table();

			// Only attempt the insert when the table exists.
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB
				return;
			}

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'form'       => substr( (string) $form, 0, 20 ),
					'ip'         => substr( $this->get_client_ip(), 0, 45 ),
					'user_id'    => (int) get_current_user_id(),
					'subject'    => substr( (string) $subject, 0, 190 ),
					'outcome'    => substr( (string) $outcome, 0, 40 ),
					'score'      => ( null === $score ) ? null : (float) $score,
					'created_at' => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%d', '%s', '%s', '%f', '%s' )
			);
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

			if ( ! $this->guard( 'login', (string) $username ) ) {
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

			if ( ! $this->guard( 'register', (string) $sanitized_user_login ) ) {
				$errors->add(
					'redeyed_sentinel_failed',
					__( '<strong>Sentinel:</strong> CAPTCHA verification failed. Please try again.', 'redeyed-sentinel' )
				);
			}

			return $errors;
		}

		/**
		 * Verify the lost-password form (lostpassword_post action).
		 *
		 * @param WP_Error $errors Existing errors (WP 5.4+ passes this).
		 * @return void
		 */
		public function verify_lostpassword( $errors = null ) {
			$options = $this->get_options();

			if ( empty( $options['enable_lostpassword'] ) || ! $this->is_configured() ) {
				return;
			}

			if ( ! $this->guard( 'lostpassword' ) ) {
				if ( is_wp_error( $errors ) ) {
					$errors->add(
						'redeyed_sentinel_failed',
						__( '<strong>Sentinel:</strong> CAPTCHA verification failed. Please try again.', 'redeyed-sentinel' )
					);
				} else {
					// Older WP without the $errors arg: stop outright.
					wp_die(
						esc_html__( 'Sentinel: CAPTCHA verification failed. Please go back and try again.', 'redeyed-sentinel' ),
						esc_html__( 'Verification Failed', 'redeyed-sentinel' ),
						array(
							'response'  => 403,
							'back_link' => true,
						)
					);
				}
			}
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
			if ( ! $this->guard( 'comment' ) ) {
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

// Create the block-log table on activation.
register_activation_hook( __FILE__, array( 'Redeyed_Sentinel', 'on_activate' ) );
