<?php
/** Run with wp --user=<authorized editor> eval-file <file> <post-id> <element-id>. No writes. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit; }
if ( ! class_exists( \Bricks\Elements::class ) || ! class_exists( Simple_Google_Login::class ) ) { WP_CLI::error( 'Activate Bricks and Simple Google Login.' ); }
$checks = 0;
$check = static function ( $passed, $message ) use ( &$checks ) { if ( ! $passed ) { WP_CLI::error( $message ); } $checks++; };
$check( isset( \Bricks\Elements::$elements['sgl-google-login'] ), 'Google must be registered during init, before the element load loop.' );
\Bricks\Elements::load_elements();
$element = \Bricks\Elements::$elements['sgl-google-login'];
foreach ( array( 'text', 'theme', 'size', 'redirect', '_width', '_widthMax' ) as $control ) {
	$check( isset( $element['controls'][ $control ] ), 'Missing Builder control: ' . $control );
}
Simple_Google_Login::register_bricks_element();
$check( $element === \Bricks\Elements::$elements['sgl-google-login'], 'Repeated registration must preserve loaded controls.' );
foreach ( array( '_width', '_widthMax', '_padding' ) as $control ) {
	foreach ( $element['controls'][ $control ]['css'] ?? array() as $css ) {
		$check( '' === ( $css['selector'] ?? '' ), 'Native layout controls must style the root: ' . $control );
	}
}
if ( empty( $args[0] ) || empty( $args[1] ) ) { WP_CLI::error( 'Provide a post ID and its Google element ID for an authorized dry run.' ); }
$ability = wp_get_ability( 'bricks/update-element' );
if ( ! $ability ) { WP_CLI::error( 'Bricks update-element ability is unavailable.' ); }
$result = $ability->execute( array( 'postId' => (int) $args[0], 'elementId' => $args[1], 'settings' => array( '_widthMax' => '300px' ), 'dryRun' => true ) );
if ( is_wp_error( $result ) ) { WP_CLI::error( $result->get_error_message() ); }
$check( ! empty( $result['valid'] ) && ! empty( $result['dryRun'] ) && empty( $result['revisionId'] ), 'Native Google layout must be editable through Bricks without a save.' );
WP_CLI::success( "$checks Bricks registration, controls and authorized editing checks passed; no page or permission writes." );
