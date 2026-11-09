<?php
/** Run with wp eval-file tests/textdomain.php on a site that has the plugin active. No writes. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit; }
if ( ! class_exists( Simple_Google_Login::class ) ) { WP_CLI::error( 'Activate Simple Google Login.' ); }
if ( false === has_action( 'init', array( Simple_Google_Login::class, 'load_textdomain' ) ) ) { WP_CLI::error( 'The translations loader must be hooked on init.' ); }
$data = get_plugin_data( dirname( __DIR__ ) . '/simple-google-login.php', false, false );
if ( '/languages' !== $data['DomainPath'] ) { WP_CLI::error( 'The Domain Path header must be /languages.' ); }
if ( ! is_readable( dirname( __DIR__ ) . '/languages/simple-google-login.pot' ) ) { WP_CLI::error( 'The .pot file is missing.' ); }
WP_CLI::success( 'Translations loader hooked, Domain Path set, .pot present.' );
