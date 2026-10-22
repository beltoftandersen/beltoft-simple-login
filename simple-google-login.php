<?php
/**
 * Plugin Name: Simple Google Login for WooCommerce
 * Description: "Continue with Google" login and registration for WooCommerce. Bricks Builder ready.
 * Version: 1.2.7
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Author: beltoft.net
 * Author URI: https://beltoft.net
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: simple-google-login
 */

defined( 'ABSPATH' ) || exit;

final class Simple_Google_Login {

	const VERSION = '1.2.7';
	const META    = '_sgl_google_sub';

	private static $loaded = false;

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'woocommerce_login_form_end', array( __CLASS__, 'auto_button' ) );
		add_shortcode( 'sgl_google_button', array( __CLASS__, 'shortcode' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
		add_action( 'bricks/load_elements/after', array( __CLASS__, 'register_bricks_element' ) );
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
	}

	public static function load_textdomain() {
		// Not hosted on WordPress.org: the translations ship in this plugin's languages/ folder, which
		// WordPress only finds when told where it is.
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
		load_plugin_textdomain( 'simple-google-login', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Register frontend assets. They are enqueued only when a button renders.
	 */
	public static function register_assets() {
		wp_register_script(
			'google-gsi',
			'https://accounts.google.com/gsi/client',
			array(),
			null,
			array(
				'strategy'  => 'async',
				'in_footer' => true,
			)
		);

		wp_register_script(
			'sgl',
			plugins_url( 'sgl.js', __FILE__ ),
			array( 'google-gsi' ),
			self::VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
	}

	private static function load_assets() {
		if ( self::$loaded ) {
			return;
		}

		self::$loaded = true;

		wp_enqueue_script( 'sgl' );
		wp_localize_script(
			'sgl',
			'SGL',
			array(
				'clientId'    => self::client_id(),
				'endpoint'     => esc_url_raw( rest_url( 'sgl/v1/login' ) ),
				'locale'       => str_replace( '_', '-', determine_locale() ),
				'genericError' => __( 'Google login failed. Please try again.', 'simple-google-login' ),
				'blocked'      => __( 'The Google button could not load. Log in with your email and password, or allow Google in your browser.', 'simple-google-login' ),
			)
		);
	}

	private static function client_id() {
		return defined( 'SGL_CLIENT_ID' ) ? trim( (string) SGL_CLIENT_ID ) : '';
	}

	/**
	 * Whether the current request is rendering the Bricks builder.
	 *
	 * @return bool
	 */
	public static function in_builder() {
		return ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() )
			|| ( function_exists( 'bricks_is_builder_call' ) && bricks_is_builder_call() );
	}

	/**
	 * Return Google button markup, or an empty string when it should not show.
	 *
	 * @param array $args Button settings.
	 * @return string
	 */
	public static function render_button( $args = array() ) {
		if ( is_user_logged_in() || self::in_builder() || '' === self::client_id() ) {
			return '';
		}

		$args = wp_parse_args(
			$args,
			array(
				'redirect' => '',
				'text'     => 'continue_with',
				'theme'    => 'outline',
				'size'     => 'large',
			)
		);

		$texts  = array( 'continue_with', 'signin_with', 'signup_with' );
		$themes = array( 'outline', 'filled_blue', 'filled_black' );
		$sizes  = array( 'small', 'medium', 'large' );

		$text  = in_array( $args['text'], $texts, true ) ? $args['text'] : 'continue_with';
		$theme = in_array( $args['theme'], $themes, true ) ? $args['theme'] : 'outline';
		$size  = in_array( $args['size'], $sizes, true ) ? $args['size'] : 'large';

		$redirect = '';
		if ( ! empty( $args['redirect'] ) ) {
			$redirect = wp_validate_redirect( esc_url_raw( $args['redirect'] ), '' );
		}

		self::load_assets();

		return sprintf(
			'<div class="sgl-wrap"><div class="sgl-btn" data-redirect="%s" data-text="%s" data-theme="%s" data-size="%s"></div></div>',
			esc_attr( $redirect ),
			esc_attr( $text ),
			esc_attr( $theme ),
			esc_attr( $size )
		);
	}

	/**
	 * Automatically place a button after standard WooCommerce login forms.
	 */
	public static function auto_button() {
		if ( ! apply_filters( 'sgl_auto_button', true ) ) {
			return;
		}

		$redirect = function_exists( 'is_checkout' ) && is_checkout() && function_exists( 'wc_get_checkout_url' )
			? wc_get_checkout_url()
			: '';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by render_button().
		echo self::render_button( array( 'redirect' => $redirect ) );
	}

	/**
	 * Render [sgl_google_button].
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'redirect' => '',
				'text'     => 'continue_with',
				'theme'    => 'outline',
				'size'     => 'large',
			),
			$atts,
			'sgl_google_button'
		);

		return self::render_button( $atts );
	}

	/**
	 * Register the native Bricks element after Bricks has loaded its elements.
	 */
	public static function register_bricks_element() {
		if ( class_exists( '\\Bricks\\Elements' ) ) {
			\Bricks\Elements::register_element(
				__DIR__ . '/class-sgl-bricks-element.php',
				'sgl-google-login',
				'SGL_Bricks_Element'
			);
		}
	}

	/**
	 * Register the public Google credential exchange endpoint.
	 */
	public static function register_route() {
		register_rest_route(
			'sgl/v1',
			'/login',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'login' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'credential' => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && '' !== $value && strlen( $value ) <= 4096;
						},
					),
				),
			)
		);
	}

	/**
	 * Replay guard: a Google ID token is accepted once. Remembered (by hash) until it expires.
	 *
	 * @param string $token Raw ID token.
	 * @param int    $exp   Its expiry (unix time).
	 * @return bool
	 */
	private static function first_use( $token, $exp ) {
		$key = 'sgl_t_' . hash( 'sha256', (string) $token );
		$ttl = max( 60, min( 2 * HOUR_IN_SECONDS, $exp - time() + 60 ) );

		// wp_cache_add is atomic with a persistent object cache (Redis here); the transient is the
		// fallback record without one.
		if ( wp_using_ext_object_cache() ) {
			return wp_cache_add( $key, 1, 'sgl', $ttl );
		}

		if ( get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, 1, $ttl );

		return true;
	}

	/**
	 * Only our own pages may log a visitor in (login CSRF): the request must be JSON (a form on
	 * another site cannot send JSON without the browser asking us first) and come from this site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	private static function same_site( WP_REST_Request $request ) {
		$type = $request->get_content_type();

		if ( empty( $type['value'] ) || 'application/json' !== strtolower( $type['value'] ) ) {
			return false;
		}

		$from = (string) $request->get_header( 'origin' );

		if ( '' === $from || 'null' === $from ) {
			$from = (string) $request->get_header( 'referer' );
		}

		$host = strtolower( (string) wp_parse_url( $from, PHP_URL_HOST ) );

		return '' !== $host && strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) === $host;
	}

	private static function throttled() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? substr( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ), 0, 100 )
			: '';

		// Fixed ten-minute windows: every request used to restart the timer, so a busy shared IP
		// (mobile carrier NAT, an office) never got out of the limit.
		$key      = 'sgl_rl_l' . (int) floor( time() / 600 ) . md5( $ip );
		$attempts = (int) get_transient( $key );
		$maximum  = max( 1, (int) apply_filters( 'sgl_max_attempts', 20 ) );

		if ( $attempts >= $maximum ) {
			return true;
		}

		set_transient( $key, $attempts + 1, 10 * MINUTE_IN_SECONDS );
		return false;
	}

	private static function verify( $token ) {
		if ( '' === $token || strlen( $token ) > 4096 ) {
			return false;
		}

		$response = wp_remote_get(
			'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode( $token ),
			array( 'timeout' => 8 )
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$claims = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $claims ) ) {
			return false;
		}

		$valid = in_array( $claims['iss'] ?? '', array( 'accounts.google.com', 'https://accounts.google.com' ), true )
			&& ( $claims['aud'] ?? '' ) === self::client_id()
			&& (int) ( $claims['exp'] ?? 0 ) > time()
			&& filter_var( $claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN )
			&& ! empty( $claims['sub'] )
			&& ! empty( $claims['email'] )
			&& self::first_use( $token, (int) ( $claims['exp'] ?? 0 ) );

		return $valid ? $claims : false;
	}

	private static function role_is_allowed( $user ) {
		$allowed = (array) apply_filters( 'sgl_allowed_roles', array( 'customer', 'subscriber' ) );
		$roles   = (array) $user->roles;

		return ! empty( $roles ) && ! array_diff( $roles, $allowed );
	}

	private static function link_existing_user( $user, $sub ) {
		// A customer can have more than one Google account linked (carried over from Super
		// Socializer's linked accounts). Any of them may sign in; an unknown one may not.
		$linked = array_filter( array_map( 'strval', (array) get_user_meta( $user->ID, self::META, false ) ) );

		if ( $linked ) {
			foreach ( $linked as $one ) {
				if ( hash_equals( $one, $sub ) ) {
					return true;
				}
			}

			return false;
		}

		if ( ! add_user_meta( $user->ID, self::META, $sub, true ) ) {
			$linked_sub = (string) get_user_meta( $user->ID, self::META, true );
			return '' !== $linked_sub && hash_equals( $linked_sub, $sub );
		}

		if ( apply_filters( 'sgl_reset_password_on_link', true, $user ) ) {
			wp_set_password( wp_generate_password( 32, true, true ), $user->ID );

			// wp_set_password() leaves existing logins alive: someone who registered this email
			// first and stayed logged in would keep the account. End every session; the login
			// that follows sets a fresh cookie.
			WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
		}

		return true;
	}

	/**
	 * Verify a Google credential, locate or create the customer, and log them in.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function login( WP_REST_Request $request ) {
		if ( '' === self::client_id() ) {
			return new WP_Error(
				'sgl_not_configured',
				esc_html__( 'Google login is not configured.', 'simple-google-login' ),
				array( 'status' => 503 )
			);
		}

		if ( ! self::same_site( $request ) ) {
			return new WP_Error(
				'sgl_forbidden',
				esc_html__( 'Google sign-in failed.', 'simple-google-login' ),
				array( 'status' => 403 )
			);
		}

		if ( self::throttled() ) {
			return new WP_Error(
				'sgl_throttled',
				esc_html__( 'Too many attempts. Try again later.', 'simple-google-login' ),
				array( 'status' => 429 )
			);
		}

		$claims = self::verify( (string) $request->get_param( 'credential' ) );
		if ( ! $claims ) {
			return new WP_Error(
				'sgl_invalid',
				esc_html__( 'Google sign-in failed.', 'simple-google-login' ),
				array( 'status' => 401 )
			);
		}

		$sub   = sanitize_text_field( (string) $claims['sub'] );
		$email = sanitize_email( (string) $claims['email'] );

		if ( '' === $sub || ! is_email( $email ) ) {
			return new WP_Error(
				'sgl_invalid',
				esc_html__( 'Google sign-in failed.', 'simple-google-login' ),
				array( 'status' => 401 )
			);
		}

		$found = get_users(
			array(
				'meta_key'   => self::META,
				'meta_value' => $sub,
				'number'     => 1,
			)
		);
		$user  = $found ? $found[0] : get_user_by( 'email', $email );

		if ( $user ) {
			if ( ! self::role_is_allowed( $user ) ) {
				return new WP_Error(
					'sgl_forbidden',
					esc_html__( 'Please log in with your password.', 'simple-google-login' ),
					array( 'status' => 403 )
				);
			}

			if ( ! self::link_existing_user( $user, $sub ) ) {
				return new WP_Error(
					'sgl_link_conflict',
					esc_html__( 'This account is already linked to another Google login.', 'simple-google-login' ),
					array( 'status' => 409 )
				);
			}
		} else {
			$registration_enabled = 'yes' === get_option( 'woocommerce_enable_myaccount_registration' )
				|| 'yes' === get_option( 'woocommerce_enable_signup_and_login_from_checkout' );

			if ( ! apply_filters( 'sgl_allow_registration', $registration_enabled ) ) {
				return new WP_Error(
					'sgl_noreg',
					esc_html__( 'Registration is disabled.', 'simple-google-login' ),
					array( 'status' => 403 )
				);
			}

			$id = wc_create_new_customer(
				$email,
				wc_create_new_customer_username( $email, array() ),
				wp_generate_password( 32, true, true ),
				array(
					'first_name' => sanitize_text_field( $claims['given_name'] ?? '' ),
					'last_name'  => sanitize_text_field( $claims['family_name'] ?? '' ),
				)
			);

			if ( is_wp_error( $id ) ) {
				return new WP_Error( 'sgl_create', $id->get_error_message(), array( 'status' => 400 ) );
			}

			add_user_meta( $id, self::META, $sub, true );
			$user = get_user_by( 'id', $id );
		}

		if ( ! $user instanceof WP_User ) {
			return new WP_Error(
				'sgl_user',
				esc_html__( 'Google login could not load the customer account.', 'simple-google-login' ),
				array( 'status' => 500 )
			);
		}

		// No wp_clear_auth_cookie() first: it adds a dozen expiring Set-Cookie headers, and the
		// front proxy rejects (502) a response whose headers pass 4 KB - the login then succeeded
		// on the server while the browser never got its cookie. The new cookies overwrite the old.
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );
		do_action( 'wp_login', $user->user_login, $user );

		return rest_ensure_response( array( 'ok' => true ) );
	}
}

Simple_Google_Login::init();
