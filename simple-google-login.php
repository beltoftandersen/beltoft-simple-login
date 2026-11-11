<?php
/**
 * Plugin Name: Simple Google Login for WooCommerce
 * Description: "Continue with Google" login and registration for WooCommerce. Bricks Builder ready.
 * Version: 1.3.9
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Author: beltoft.net
 * Author URI: https://beltoft.net
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: simple-google-login
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

final class Simple_Google_Login {

	const VERSION = '1.3.9';
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
		// Bricks registers its base classes on init. Be present before it loads
		// element controls and snapshots its available editing permissions.
		add_action( 'init', array( __CLASS__, 'register_bricks_element' ), 11 );
		add_action( 'bricks/load_elements/before', array( __CLASS__, 'register_bricks_element' ) );
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
		// The button language: Google reads hl from the script address. The locale option of renderButton alone was
		// overruled by the visitor's location, so a server-side visit from Germany got "Weiter mit Google".
		$language = strtolower( (string) strtok( determine_locale(), '_' ) );

		wp_register_script(
			'google-gsi',
			add_query_arg( 'hl', rawurlencode( '' !== $language ? $language : 'en' ), 'https://accounts.google.com/gsi/client' ),
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
		$height = array( 'small' => 24, 'medium' => 36, 'large' => 44 )[ $size ];

		$redirect = '';
		if ( ! empty( $args['redirect'] ) ) {
			$redirect = wp_validate_redirect( esc_url_raw( $args['redirect'] ), '' );
		}

		if ( '' === $redirect ) {
			$redirect = self::requested_redirect();
		}

		self::load_assets();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks one of our fixed messages.
		$code    = isset( $_GET['sgl_error'] ) ? sanitize_key( wp_unslash( $_GET['sgl_error'] ) ) : '';
		$message = self::messages()[ $code ] ?? '';
		$error   = '' !== $message ? '<div class="sgl-error woocommerce-error" role="alert" style="margin-top:8px">' . esc_html( $message ) . '</div>' : '';

		return sprintf(
			'<div class="sgl-wrap" style="position:relative"><div class="sgl-btn" style="height:%dpx" data-redirect="%s" data-text="%s" data-theme="%s" data-size="%s"></div>%s%s</div>',
			$height,
			esc_attr( $redirect ),
			esc_attr( $text ),
			esc_attr( $theme ),
			esc_attr( $size ),
			self::placeholder( $text, $theme, $size, $height ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from fixed parts, text escaped.
			$error // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		);
	}

	/**
	 * A stand-in for Google's button, shown in its place until Google has drawn the real one.
	 *
	 * Google draws its button in a frame of its own, and for a visitor who is signed in to Google it first looks up
	 * their account ("Continue as ..."), which can take seconds in a browser that restricts third-party cookies. Until
	 * then the spot was empty. The stand-in looks like Google's button, is not clickable and is hidden from screen
	 * readers; sgl.js hides it when the real button is ready or when an error is shown. Its styles are inline, so it
	 * is right from the first paint.
	 *
	 * @param string $text   Button text key.
	 * @param string $theme  Button theme.
	 * @param string $size   Button size.
	 * @param int    $height Height in pixels.
	 * @return string
	 */
	private static function placeholder( $text, $theme, $size, $height ) {
		$labels = array(
			'continue_with' => __( 'Continue with Google', 'simple-google-login' ),
			'signin_with'   => __( 'Sign in with Google', 'simple-google-login' ),
			'signup_with'   => __( 'Sign up with Google', 'simple-google-login' ),
		);
		$looks  = array(
			'outline'      => 'background:#fff;color:#3c4043;border:1px solid #dadce0',
			'filled_blue'  => 'background:#1a73e8;color:#fff;border:1px solid #1a73e8',
			'filled_black' => 'background:#202124;color:#fff;border:1px solid #202124',
		);
		$font   = array( 'small' => 12, 'medium' => 14, 'large' => 14 )[ $size ];
		$icon   = '<svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true" focusable="false"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>';

		return sprintf(
			'<div class="sgl-placeholder" aria-hidden="true" style="position:absolute;top:0;left:50%%;transform:translateX(-50%%);box-sizing:border-box;width:100%%;max-width:400px;height:%1$dpx;display:flex;align-items:center;justify-content:center;gap:10px;border-radius:4px;%2$s;font:500 %3$dpx/1 Roboto,Arial,sans-serif;letter-spacing:.25px;white-space:nowrap;pointer-events:none">%4$s<span>%5$s</span></div>',
			(int) $height,
			$looks[ $theme ],
			(int) $font,
			$icon,
			esc_html( $labels[ $text ] )
		);
	}

	/**
	 * The customer's message for each error code a login can end with.
	 *
	 * @return array<string, string>
	 */
	private static function messages() {
		return array(
			'sgl_not_configured' => __( 'Google login is not configured.', 'simple-google-login' ),
			'sgl_forbidden'      => __( 'Google sign-in failed.', 'simple-google-login' ),
			'sgl_invalid'        => __( 'Google sign-in failed.', 'simple-google-login' ),
			'sgl_throttled'      => __( 'Too many attempts. Try again later.', 'simple-google-login' ),
			'sgl_staff'          => __( 'Please log in with your password.', 'simple-google-login' ),
			'sgl_link_conflict'  => __( 'This account is already linked to another Google login.', 'simple-google-login' ),
			'sgl_noreg'          => __( 'Registration is disabled.', 'simple-google-login' ),
			'sgl_create'         => __( 'Google login could not create the customer account.', 'simple-google-login' ),
			'sgl_user'           => __( 'Google login could not load the customer account.', 'simple-google-login' ),
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
	 * Register before Bricks loads controls, without overwriting a loaded instance.
	 */
	public static function register_bricks_element() {
		if ( class_exists( \Bricks\Elements::class ) && class_exists( \Bricks\Element::class ) && ! isset( \Bricks\Elements::$elements['sgl-google-login'] ) ) {
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
	/**
	 * The address the login page was asked to go on to: the standard redirect_to parameter in its own
	 * address (a "Log in" link from the checkout, for example). Only an address on this site counts.
	 *
	 * @return string '' when there is none.
	 */
	private static function requested_redirect() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only a validated address on this site is used.
		$url = isset( $_GET['redirect_to'] ) && is_string( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';

		return '' !== $url ? (string) wp_validate_redirect( $url, '' ) : '';
	}

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
				return new WP_Error( 'sgl_staff', esc_html__( 'Please log in with your password.', 'simple-google-login' ) );
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

		$redirect = '' !== $redirect ? $redirect : self::requested_redirect();
		$redirect = '' !== $redirect ? $redirect : ( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' ) );

		if ( is_user_logged_in() ) {
			wp_safe_redirect( $redirect );
			exit;
		}

		$user = self::sign_in( $token );

		if ( is_wp_error( $user ) ) {
			// A logged-out visitor has no WooCommerce session for a notice: the error code goes back in the address
			// and the button prints its own message (render_button). Only a known code, never text, is passed.
			$back = wp_get_referer() ? wp_get_referer() : $redirect;
			wp_safe_redirect( add_query_arg( 'sgl_error', rawurlencode( $user->get_error_code() ), remove_query_arg( 'sgl_error', $back ) ) );
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
