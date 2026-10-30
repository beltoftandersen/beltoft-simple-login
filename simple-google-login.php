<?php
/**
 * Plugin Name: Simple Google Login for WooCommerce
 * Description: "Continue with Google" login and registration for WooCommerce. Bricks Builder ready.
 * Version: 1.3.0
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

	const VERSION = '1.3.0';
	const FIELD   = 'sgl_credential';
	const META    = '_sgl_google_sub';

	private static $loaded = false;

	/**
	 * The verified claims of this request's login.
	 *
	 * @var array<string, mixed>
	 */
	private static $claims = array();

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'woocommerce_login_form_end', array( __CLASS__, 'auto_button' ) );
		add_shortcode( 'sgl_google_button', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'form_login' ), 10 );
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
				'field'        => self::FIELD,
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
	 * Only our own pages may log a visitor in (login CSRF): a form post carries the page it came from (Origin, or
	 * Referer when a browser leaves Origin out), and it must be this site.
	 *
	 * @return bool
	 */
	private static function same_site() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only the host is compared.
		$from = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] ) : '';

		if ( '' === $from || 'null' === $from ) {
			$from = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
		}
		// phpcs:enable

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

	/**
	 * Fills the account and WooCommerce billing names (and billing email) from the Google profile,
	 * only where they are still empty: checkout reads the billing fields, which account creation
	 * alone leaves blank. A name the customer typed is never overwritten.
	 *
	 * @param WP_User              $user   Customer.
	 * @param array<string, mixed> $claims Verified ID token claims.
	 */
	private static function fill_names( $user, $claims ) {
		if ( ! apply_filters( 'sgl_fill_names', true, $user, $claims ) || ! class_exists( 'WC_Customer' ) ) {
			return;
		}

		$first = sanitize_text_field( (string) ( $claims['given_name'] ?? '' ) );
		$last  = sanitize_text_field( (string) ( $claims['family_name'] ?? '' ) );

		try {
			$customer = new WC_Customer( $user->ID );
		} catch ( Exception $e ) {
			return;
		}

		// The account's own name (typed by the customer) wins; Google's fills only what is empty.
		if ( '' === (string) $customer->get_first_name() && '' !== $first ) {
			$customer->set_first_name( $first );
		}
		if ( '' === (string) $customer->get_last_name() && '' !== $last ) {
			$customer->set_last_name( $last );
		}
		if ( '' === (string) $customer->get_billing_first_name() && '' !== (string) $customer->get_first_name() ) {
			$customer->set_billing_first_name( $customer->get_first_name() );
		}
		if ( '' === (string) $customer->get_billing_last_name() && '' !== (string) $customer->get_last_name() ) {
			$customer->set_billing_last_name( $customer->get_last_name() );
		}
		if ( '' === (string) $customer->get_billing_email() ) {
			$customer->set_billing_email( $user->user_email );
		}

		$customer->save();
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
	 * The customer for verified Google claims: the one linked to that Google account, the one with the same email
	 * (linked now), or a new one when WooCommerce allows registration.
	 *
	 * @param array<string, mixed> $claims Verified ID token claims.
	 * @return WP_User|WP_Error
	 */
	private static function customer( array $claims ) {
		$sub   = sanitize_text_field( (string) $claims['sub'] );
		$email = sanitize_email( (string) $claims['email'] );

		if ( '' === $sub || ! is_email( $email ) ) {
			return new WP_Error( 'sgl_invalid', esc_html__( 'Google sign-in failed.', 'simple-google-login' ) );
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
				return new WP_Error( 'sgl_forbidden', esc_html__( 'Please log in with your password.', 'simple-google-login' ) );
			}

			if ( ! self::link_existing_user( $user, $sub ) ) {
				return new WP_Error( 'sgl_link_conflict', esc_html__( 'This account is already linked to another Google login.', 'simple-google-login' ) );
			}
		} else {
			$registration_enabled = 'yes' === get_option( 'woocommerce_enable_myaccount_registration' )
				|| 'yes' === get_option( 'woocommerce_enable_signup_and_login_from_checkout' );

			if ( ! apply_filters( 'sgl_allow_registration', $registration_enabled ) ) {
				return new WP_Error( 'sgl_noreg', esc_html__( 'Registration is disabled.', 'simple-google-login' ) );
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
				return new WP_Error( 'sgl_create', $id->get_error_message() );
			}

			add_user_meta( $id, self::META, $sub, true );
			$user = get_user_by( 'id', $id );
		}

		return $user instanceof WP_User ? $user : new WP_Error( 'sgl_user', esc_html__( 'Google login could not load the customer account.', 'simple-google-login' ) );
	}

	/**
	 * The login itself, as a normal page load (a form post from sgl.js), like WooCommerce's own login form: the
	 * cookie is set and wp_login fires in the browser's request, so a two-factor plugin that hooks wp_login (WP 2FA)
	 * shows its code step to the customer before the login counts. Google never skips the second step.
	 */
	public static function form_login() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Login CSRF is checked with same_site(); the Google token is single use.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST[ self::FIELD ] ) ) {
			return;
		}

		$token    = is_string( $_POST[ self::FIELD ] ) ? trim( wp_unslash( $_POST[ self::FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A JWT, checked with Google.
		$redirect = isset( $_POST['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ), '' ) : '';
		// phpcs:enable

		$redirect = '' !== $redirect ? $redirect : ( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' ) );

		if ( is_user_logged_in() ) {
			wp_safe_redirect( $redirect );
			exit;
		}

		$user = self::sign_in( $token );

		if ( is_wp_error( $user ) ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( $user->get_error_message(), 'error' );
			}
			wp_safe_redirect( wp_get_referer() ? wp_get_referer() : $redirect );
			exit;
		}

		self::fill_names( $user, self::$claims );

		// No wp_clear_auth_cookie() first: it adds a dozen expiring Set-Cookie headers, and the front proxy rejects
		// (502) a response whose headers pass 4 KB. The new cookies overwrite the old.
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );

		// A two-factor plugin takes over here: it ends this login, prints its code step and stops. WP 2FA sends
		// the customer to redirect_to once the code is right.
		$_REQUEST['redirect_to'] = $redirect;
		do_action( 'wp_login', $user->user_login, $user );

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * The checks before a login: configured, from this site, not throttled, a valid single-use Google token.
	 *
	 * @param string $token Google ID token.
	 * @return WP_User|WP_Error
	 */
	private static function sign_in( $token ) {
		if ( '' === self::client_id() ) {
			return new WP_Error( 'sgl_not_configured', esc_html__( 'Google login is not configured.', 'simple-google-login' ) );
		}

		if ( ! self::same_site() ) {
			return new WP_Error( 'sgl_forbidden', esc_html__( 'Google sign-in failed.', 'simple-google-login' ) );
		}

		if ( self::throttled() ) {
			return new WP_Error( 'sgl_throttled', esc_html__( 'Too many attempts. Try again later.', 'simple-google-login' ) );
		}

		$claims = self::verify( $token );

		if ( ! $claims ) {
			return new WP_Error( 'sgl_invalid', esc_html__( 'Google sign-in failed.', 'simple-google-login' ) );
		}

		self::$claims = $claims;

		return self::customer( $claims );
	}
}

Simple_Google_Login::init();
