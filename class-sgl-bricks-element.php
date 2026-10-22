<?php
/**
 * Native Bricks element for Simple Google Login.
 *
 * @package SimpleGoogleLogin
 */

defined( 'ABSPATH' ) || exit;

class SGL_Bricks_Element extends \Bricks\Element {

	public $category     = 'woocommerce';
	public $name         = 'sgl-google-login';
	public $icon         = 'ti-google';
	public $css_selector = '.sgl-element';

	public function get_label() {
		return esc_html__( 'Google Login', 'simple-google-login' );
	}

	public function set_controls() {
		$this->controls['text'] = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Button text', 'simple-google-login' ),
			'type'    => 'select',
			'options' => array(
				'continue_with' => esc_html__( 'Continue with Google', 'simple-google-login' ),
				'signin_with'   => esc_html__( 'Sign in with Google', 'simple-google-login' ),
				'signup_with'   => esc_html__( 'Sign up with Google', 'simple-google-login' ),
			),
			'default' => 'continue_with',
		);

		$this->controls['theme'] = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Style', 'simple-google-login' ),
			'type'    => 'select',
			'options' => array(
				'outline'      => esc_html__( 'Outline', 'simple-google-login' ),
				'filled_blue'  => esc_html__( 'Filled blue', 'simple-google-login' ),
				'filled_black' => esc_html__( 'Filled black', 'simple-google-login' ),
			),
			'default' => 'outline',
		);

		$this->controls['size'] = array(
			'tab'     => 'content',
			'label'   => esc_html__( 'Size', 'simple-google-login' ),
			'type'    => 'select',
			'options' => array(
				'small'  => esc_html__( 'Small', 'simple-google-login' ),
				'medium' => esc_html__( 'Medium', 'simple-google-login' ),
				'large'  => esc_html__( 'Large', 'simple-google-login' ),
			),
			'default' => 'large',
		);

		$this->controls['redirect'] = array(
			'tab'         => 'content',
			'label'       => esc_html__( 'Redirect after login', 'simple-google-login' ),
			'type'        => 'text',
			'placeholder' => esc_html__( 'Empty = reload this page', 'simple-google-login' ),
		);
	}

	public function render() {
		$this->set_attribute( '_root', 'class', 'sgl-element' );

		if ( ! $this->is_frontend ) {
			echo '<div ' . $this->render_attributes( '_root' ) . '>';
			echo '<div style="padding:10px 14px;border:1px dashed #aaa;border-radius:4px;display:inline-block">';
			echo esc_html__( 'Google Login button (shown on the frontend to logged-out visitors)', 'simple-google-login' );
			echo '</div></div>';
			return;
		}

		$markup = Simple_Google_Login::render_button(
			array(
				'text'     => $this->settings['text'] ?? 'continue_with',
				'theme'    => $this->settings['theme'] ?? 'outline',
				'size'     => $this->settings['size'] ?? 'large',
				'redirect' => $this->settings['redirect'] ?? '',
			)
		);

		if ( '' === $markup ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Root attributes are escaped by Bricks; button by SGL.
		echo '<div ' . $this->render_attributes( '_root' ) . '>' . $markup . '</div>';
	}
}
