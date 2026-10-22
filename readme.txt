=== Simple Google Login for WooCommerce ===
Contributors: beltoftnet
Tags: google, login, woocommerce, social login, bricks
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.2.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

"Continue with Google" login and registration for WooCommerce customers, with Google's own button. Bricks Builder ready.

== Description ==

Adds Google's official "Continue with Google" button (Google Identity Services) to WooCommerce:

* automatically after WooCommerce's login forms (My Account, checkout);
* anywhere with the shortcode `[sgl_google_button]`;
* as a native Bricks element, "Google Login".

The visitor's Google ID token is checked with Google: audience, issuer, expiry and a verified email. Each token is
accepted once. The plugin then logs in the customer linked to that Google account, links the existing customer
with the same email, or creates a new customer when WooCommerce allows registration. Only customer and subscriber
accounts can sign in this way; staff log in with their password.

There is no settings screen. The Google client ID goes in `wp-config.php`. Full setup, filters and troubleshooting
are in README.md.

== Installation ==

1. In Google Cloud Console, create an OAuth client ID of type "Web application". Add your site's address
   (for example `https://example.com`) under "Authorized JavaScript origins". No redirect URI is needed.
2. Add the client ID to `wp-config.php`:
   `define( 'SGL_CLIENT_ID', '1234567890-abc.apps.googleusercontent.com' );`
3. Upload the plugin to `wp-content/plugins/simple-google-login/` and activate it. WooCommerce is required.
4. Log out and open My Account: the Google button appears under the login form.

== Frequently Asked Questions ==

= The button doesn't show. =

`SGL_CLIENT_ID` isn't defined, you're logged in, or you're looking at the Bricks builder, where the element shows a
placeholder instead.

= Google says the origin is not allowed. =

Add the exact site origin (scheme and host, no path) to the OAuth client's Authorized JavaScript origins.

= Can administrators sign in with Google? =

No. Only the roles in the `sgl_allowed_roles` filter (default: customer, subscriber) can.

== Changelog ==

= 1.2.4 =
* License header and readme.txt added. An escaping comment added in the Bricks element's builder preview. No
  behaviour change.

= 1.2.3 =
* Removed the unused one-time nonce route and helper. Login behaviour is unchanged.

= 1.2.2 =
* First version in its own repository.
