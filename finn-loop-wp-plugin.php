<?php
/**
 * Plugin Name:       Finn-Loop Connect
 * Plugin URI:        https://github.com/Kananalibayov/finn-loop-wp-plugin
 * Description:       Companion plugin for Finn-Loop. Connects this WordPress to your agency platform via a one-time pairing code — enables remote management, health reporting, and SSO from the dashboard.
 * Version:           0.2.0
 * Author:            Finn-Loop
 * Author URI:        https://github.com/Kananalibayov/finn-loop
 * Text Domain:       finn-loop-connect
 * Requires at least: 6.0
 * Tested up to:      6.5
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package FinnLoop_Connect
 */

// Exit if accessed directly — standard WP guard.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single class for the Finn-Loop Connect plugin.
 *
 * Phase 1 (issue #60): pairing-code auto-connect.
 * - Adds a Tools → Finn-Loop Connect submenu page.
 * - Settings form collects Platform URL + Pairing code.
 * - On submit: creates an Application Password for the current (manage_options)
 *   user and POSTs to {platformUrl}/api/wp/pairing/register.
 * - Stores platform URL + connection ID in a local option on success.
 *
 * Future phases (#61 SSO, #62 health, #63 settings sync) layer on top.
 */
final class FinnLoop_Connect {

	/**
	 * The WP option key for the stored connection state.
	 *
	 * Shape after successful pairing:
	 *   [
	 *     'platformUrl'  => 'https://agency.example.com',
	 *     'connectionId' => 42,
	 *     'username'     => 'admin',
	 *     'pairedAt'     => '2026-07-26T00:00:00Z',
	 *   ]
	 *
	 * NOT stored: the Application Password. It lives on the platform side
	 * (POSTed during pairing); the plugin never needs it again because the
	 * platform calls *into* WP (via REST + the password the platform holds).
	 *
	 * @var string
	 */
	const OPTION_KEY = 'finn_loop_connect_settings';

	/**
	 * Singleton instance.
	 *
	 * @var FinnLoop_Connect|null
	 */
	private static $instance = null;

	/**
	 * Instantiate the singleton on `plugins_loaded`.
	 *
	 * @return FinnLoop_Connect
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — wires up hooks.
	 */
	private function __construct() {
		// Register the admin menu + settings page.
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		// Handle the form submission on admin_init (before the page renders).
		add_action( 'admin_init', array( $this, 'handle_form_submission' ) );
		// Show admin notices (success / error) from the last action.
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
		// AC-6 (issue #61): register the SSO REST endpoint.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * AC-2: register the submenu page under Tools.
	 *
	 * @return void
	 */
	public function register_admin_menu() {
		add_submenu_page(
			'tools.php',
			__( 'Finn-Loop Connect', 'finn-loop-connect' ),
			__( 'Finn-Loop Connect', 'finn-loop-connect' ),
			'manage_options',
			'finn-loop-connect',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Get the stored connection state, or null if not paired.
	 *
	 * @return array|null
	 */
	private function get_settings() {
		$s = get_option( self::OPTION_KEY, null );
		if ( ! is_array( $s ) || empty( $s['connectionId'] ) ) {
			return null;
		}
		return $s;
	}

	/**
	 * AC-3, AC-4: handle the POST from the settings form.
	 *
	 * Verifies the nonce, sanitizes the inputs, runs the pairing, and stores
	 * the result in a transient for the next render to surface as a notice.
	 *
	 * @return void
	 */
	public function handle_form_submission() {
		// Only act on our form submission.
		if ( ! isset( $_POST['finn_loop_connect_action'] ) ) {
			return;
		}

		// Capability + nonce check.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'finn-loop-connect' ) );
		}
		check_admin_referer( 'finn_loop_connect_pair' );

		$action = sanitize_text_field( wp_unslash( $_POST['finn_loop_connect_action'] ) );

		// AC-5: Disconnect clears the local option (no platform call).
		if ( 'disconnect' === $action ) {
			delete_option( self::OPTION_KEY );
			set_transient( 'finn_loop_connect_notice', array(
				'type'    => 'success',
				'message' => __( 'Disconnected. The platform-side connection still exists until you delete it there.', 'finn-loop-connect' ),
			), 60 );
			return;
		}

		// Pair action.
		if ( 'pair' !== $action ) {
			return;
		}

		$platform_url = isset( $_POST['finn_loop_platform_url'] )
			? esc_url_raw( wp_unslash( $_POST['finn_loop_platform_url'] ) )
			: '';
		$pairing_code = isset( $_POST['finn_loop_pairing_code'] )
			? sanitize_text_field( wp_unslash( $_POST['finn_loop_pairing_code'] ) )
			: '';

		// Validate required fields.
		if ( '' === $platform_url || '' === $pairing_code ) {
			set_transient( 'finn_loop_connect_notice', array(
				'type'    => 'error',
				'message' => __( 'Platform URL and pairing code are both required.', 'finn-loop-connect' ),
			), 60 );
			return;
		}

		$result = $this->do_pairing( $platform_url, $pairing_code );
		set_transient( 'finn_loop_connect_notice', $result, 60 );
	}

	/**
	 * AC-4: the core pairing logic.
	 *
	 * Creates an Application Password for the current user, POSTs to the
	 * platform's /api/wp/pairing/register endpoint, and on success stores the
	 * connection state locally.
	 *
	 * @param string $platform_url The agency platform base URL (no trailing slash).
	 * @param string $pairing_code The one-time code from the platform's /connections page.
	 * @return array{type:string,message:string} Notice payload.
	 */
	private function do_pairing( $platform_url, $pairing_code ) {
		// AC-3 guard: Application Passwords require WP 5.6+.
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'This WordPress version does not support Application Passwords (requires WP 5.6+).', 'finn-loop-connect' ),
			);
		}

		$user_id = get_current_user_id();
		$user    = wp_get_current_user();

		// AC-4c: create an Application Password for the current user.
		$ap = WP_Application_Passwords::create_new_application_password(
			$user_id,
			array( 'name' => 'Finn-Loop Connect' )
		);

		if ( is_wp_error( $ap ) ) {
			return array(
				'type'    => 'error',
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Could not create an Application Password: %s', 'finn-loop-connect' ),
					$ap->get_error_message()
				),
			);
		}

		// `create_new_application_password` returns [ $item, $password ].
		$app_password = is_array( $ap ) && isset( $ap[1] ) ? $ap[1] : '';

		if ( '' === $app_password ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Application Password creation returned an empty password. Please retry.', 'finn-loop-connect' ),
			);
		}

		// AC-4d: POST to the platform's register endpoint.
		$endpoint = rtrim( $platform_url, '/' ) . '/api/wp/pairing/register';
		$body     = array(
			'code'        => $pairing_code,
			'siteUrl'     => home_url(),
			'username'    => $user->user_login,
			'appPassword' => $app_password,
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
					'User-Agent'   => 'FinnLoopConnect/' . $this->plugin_version() . ' (WP plugin; pairing)',
				),
				'body'        => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'type'    => 'error',
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Could not reach the platform: %s', 'finn-loop-connect' ),
					$response->get_error_message()
				),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		if ( ! is_array( $json ) || empty( $json['ok'] ) ) {
			$msg = is_array( $json ) && ! empty( $json['error'] )
				? $json['error']
				: sprintf(
					/* translators: %d: HTTP status code */
					__( 'The platform rejected the pairing (HTTP %d). Check the code and try again.', 'finn-loop-connect' ),
					$code
				);
			return array(
				'type'    => 'error',
				'message' => $msg,
			);
		}

		// AC-4e: store the connection state locally.
		update_option(
			self::OPTION_KEY,
			array(
				'platformUrl'  => rtrim( $platform_url, '/' ),
				'connectionId' => isset( $json['connectionId'] ) ? intval( $json['connectionId'] ) : 0,
				'username'     => $user->user_login,
				'pairedAt'     => current_time( 'mysql', true ),
			),
			false
		);

		return array(
			'type'    => 'success',
			'message' => sprintf(
				/* translators: %d: connection id */
				__( 'Connected! Connection #%d created on the platform.', 'finn-loop-connect' ),
				isset( $json['connectionId'] ) ? intval( $json['connectionId'] ) : 0
			),
		);
	}

	/**
	 * AC-3, AC-5: render the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'finn-loop-connect' ) );
		}

		$settings = $this->get_settings();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

		<?php if ( $settings ) : ?>
			<?php // AC-5: connected state. ?>
			<div class="card" style="max-width: 600px; padding: 16px; margin-top: 16px;">
				<h2 class="title"><?php esc_html_e( 'Connected', 'finn-loop-connect' ); ?> ✓</h2>
				<p>
					<?php
					printf(
						/* translators: 1: platform URL, 2: connection id */
						esc_html__( 'Connected to %1$s (connection #%2$d).', 'finn-loop-connect' ),
						'<code>' . esc_html( $settings['platformUrl'] ) . '</code>',
						intval( $settings['connectionId'] )
					);
					?>
				</p>
				<p class="description">
					<?php esc_html_e( 'Paired user:', 'finn-loop-connect' ); ?>
					<code><?php echo esc_html( $settings['username'] ); ?></code>
					· <?php esc_html_e( 'Paired at:', 'finn-loop-connect' ); ?>
					<?php echo esc_html( $settings['pairedAt'] ); ?> UTC
				</p>

				<form method="post" action="" style="margin-top: 16px;">
					<?php wp_nonce_field( 'finn_loop_connect_pair' ); ?>
					<input type="hidden" name="finn_loop_connect_action" value="disconnect" />
					<?php submit_button( __( 'Disconnect', 'finn-loop-connect' ), 'delete', 'submit', false ); ?>
				</form>
			</div>

		<?php else : ?>
			<?php // AC-3: pairing form. ?>
			<div class="card" style="max-width: 600px; padding: 16px; margin-top: 16px;">
				<h2 class="title"><?php esc_html_e( 'Connect to Finn-Loop', 'finn-loop-connect' ); ?></h2>
				<p>
					<?php esc_html_e( 'Generate a pairing code on your agency platform’s Connections page, then enter it here. The plugin will create an Application Password and register this WordPress with the platform automatically.', 'finn-loop-connect' ); ?>
				</p>
				<form method="post" action="">
					<?php wp_nonce_field( 'finn_loop_connect_pair' ); ?>
					<input type="hidden" name="finn_loop_connect_action" value="pair" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="finn_loop_platform_url"><?php esc_html_e( 'Platform URL', 'finn-loop-connect' ); ?></label>
							</th>
							<td>
								<input
									type="url"
									id="finn_loop_platform_url"
									name="finn_loop_platform_url"
									class="regular-text"
									placeholder="https://your-agency-platform.com"
									required
								/>
								<p class="description"><?php esc_html_e( 'The base URL of your agency’s Finn-Loop platform.', 'finn-loop-connect' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="finn_loop_pairing_code"><?php esc_html_e( 'Pairing code', 'finn-loop-connect' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="finn_loop_pairing_code"
									name="finn_loop_pairing_code"
									class="regular-text"
									placeholder="XXXX-XXXX-XXXX"
									required
								/>
								<p class="description"><?php esc_html_e( 'A one-time code from the platform’s Connections → Generate Pairing Code. Expires in 24 hours.', 'finn-loop-connect' ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Connect', 'finn-loop-connect' ), 'primary', 'submit', false ); ?>
				</form>
			</div>
		<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * AC-6: surface the result of the last action as a WP admin notice.
	 *
	 * @return void
	 */
	public function render_admin_notices() {
		// Only on our own page.
		$screen = get_current_screen();
		if ( ! $screen || 'tools_page_finn-loop-connect' !== $screen->id ) {
			return;
		}

		$notice = get_transient( 'finn_loop_connect_notice' );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( 'finn_loop_connect_notice' );

		$type    = isset( $notice['type'] ) ? $notice['type'] : 'info';
		$message = isset( $notice['message'] ) ? $notice['message'] : '';
		$classes = 'success' === $type ? 'notice notice-success is-dismissible' : 'notice notice-error is-dismissible';

		printf(
			'<div class="%s"><p>%s</p></div>',
			esc_attr( $classes ),
			wp_kses( $message, array( 'code' => array() ) ) // allow <code> tags in messages.
		);
	}

	/**
	 * The plugin's version (from the header).
	 *
	 * @return string
	 */
	/**
	 * AC-6 (issue #61): register the SSO REST endpoint.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		register_rest_route(
			'finn-loop/v1',
			'/sso',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_sso_login' ),
				'permission_callback' => '__return_true', // Public — the token is the credential.
			)
		);
	}

	/**
	 * AC-6 (issue #61): the SSO login handler.
	 *
	 * Receives a single-use token, validates it against the platform, and on
	 * success logs the browser in as the paired WP user + redirects to admin.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @return WP_REST_Response|void
	 */
	public function handle_sso_login( $request ) {
		$token = $request->get_param( 'token' );
		if ( ! $token || ! is_string( $token ) ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Missing token.', 'finn-loop-connect' ) ),
				400
			);
		}

		// AC-6(b): need the stored platform URL + connection ID.
		$settings = $this->get_settings();
		if ( ! $settings ) {
			return new WP_REST_Response(
				array( 'message' => __( 'This site is not paired with Finn-Loop.', 'finn-loop-connect' ) ),
				403
			);
		}

		$platform_url  = $settings['platformUrl'];
		$connection_id = intval( $settings['connectionId'] );

		// AC-6(c): validate the token by calling the platform back.
		$endpoint = rtrim( $platform_url, '/' ) . '/api/wp/connections/' . $connection_id . '/validate-login-token';
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
					'User-Agent'   => 'FinnLoopConnect/' . $this->plugin_version() . ' (WP plugin; SSO)',
				),
				'body'        => wp_json_encode( array( 'token' => $token ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_REST_Response(
				array(
					'message' => sprintf(
						/* translators: %s: error message */
						__( 'Could not reach the platform to validate the token: %s', 'finn-loop-connect' ),
						$response->get_error_message()
					),
				),
				502
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		if ( ! is_array( $json ) || empty( $json['ok'] ) ) {
			$msg = is_array( $json ) && ! empty( $json['error'] )
				? $json['error']
				: __( 'Token validation failed.', 'finn-loop-connect' );
			return new WP_REST_Response( array( 'message' => $msg ), 403 );
		}

		// AC-6(d): log in as the paired user.
		$username = isset( $json['username'] ) ? sanitize_text_field( $json['username'] ) : '';
		if ( '' === $username ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Platform returned no username for this token.', 'finn-loop-connect' ) ),
				403
			);
		}

		$user = get_user_by( 'login', $username );
		if ( ! $user ) {
			return new WP_REST_Response(
				array(
					'message' => sprintf(
						/* translators: %s: username */
						__( 'User "%s" does not exist on this WordPress.', 'finn-loop-connect' ),
						$username
					),
				),
				403
			);
		}

		// Log in + redirect to wp-admin.
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );

		// Use a 302 redirect so the browser follows it immediately.
		wp_safe_redirect( admin_url() );
		exit;
	}

	/**
	 * The plugin's version (from the header).
	 *
	 * @return string
	 */
	private function plugin_version() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data( __FILE__, false, false );
		return isset( $data['Version'] ) ? $data['Version'] : '0.0.0';
	}
}

// Boot the plugin on `plugins_loaded`.
add_action( 'plugins_loaded', array( 'FinnLoop_Connect', 'instance' ) );
