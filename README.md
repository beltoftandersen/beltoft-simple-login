# Simple Google Login for WooCommerce

"Continue with Google" sign-in and registration for WooCommerce customers, using Google Identity Services (the
official Google button). It works with Bricks (a native element) and with page caches. No settings screen: one
`wp-config.php` constant, plus a Google Cloud OAuth client.

Folder and text domain: `simple-google-login`. Repository: `beltoftandersen/beltoft-simple-login`.

## What it does

- Renders Google's own button where you put it:
  - after WooCommerce's standard login forms, automatically;
  - with the shortcode `[sgl_google_button]`;
  - with the Bricks element "Google Login".
- The button only shows to logged-out visitors, never inside the Bricks builder, and never when no client ID is
  configured.
- When the visitor picks a Google account, the browser posts Google's ID token to `POST /wp-json/sgl/v1/login`.
- The server checks the token with Google (`oauth2.googleapis.com/tokeninfo`): issuer, audience = your client ID,
  expiry and a verified email. Each token is accepted only once.
- It then finds the customer, or creates one, and logs them in:
  1. A user already linked to that Google account (user meta `_sgl_google_sub`) is logged in. A user may have
     several Google accounts linked; only those may sign in.
  2. Otherwise, a user with the same email is linked to this Google account and logged in. The first time, their
     WordPress password is replaced with a random one (filter `sgl_reset_password_on_link`), so from then on that
     account signs in with Google or a password reset.
  3. Otherwise, a new WooCommerce customer is created (first and last name from Google). This only happens if
     WooCommerce allows registration on My Account or at checkout (filter `sgl_allow_registration`).
- Only `customer` and `subscriber` accounts can use Google sign-in (filter `sgl_allowed_roles`). Shop managers and
  administrators get "Please log in with your password."
- After login the page reloads, or goes to the button's redirect. On the checkout the automatic button returns to
  the checkout.

## Setup

### 1. Google Cloud OAuth client

1. In Google Cloud Console, open *APIs & Services › Credentials › Create credentials › OAuth client ID*, type
   **Web application**. Set up the OAuth consent screen first if Google asks, with your site name, support email
   and privacy policy URL.
2. Under **Authorized JavaScript origins**, add every origin the button will appear on, for example
   `https://example.com`. Add staging too (`https://staging.example.com`) if it uses the same client. No paths, no
   trailing slash.
3. **Authorized redirect URIs** are not needed: the button returns the credential to JavaScript, not by redirect.
4. Copy the client ID (`…apps.googleusercontent.com`). The client secret is not used.

### 2. wp-config.php

```php
define( 'SGL_CLIENT_ID', '1234567890-abc123.apps.googleusercontent.com' );
```

Without it the plugin does nothing: no button, and the login route answers 503.

### 3. Install and activate

Copy the folder to `wp-content/plugins/simple-google-login/` and activate it. It requires WooCommerce
(`Requires Plugins: woocommerce`).

### 4. Place the button

- **Automatic:** after WooCommerce login forms (`woocommerce_login_form_end`). To switch this off:
  `add_filter( 'sgl_auto_button', '__return_false' );`
- **Shortcode:** `[sgl_google_button redirect="/a-minha-conta/" text="continue_with" theme="outline" size="large"]`
  - `text`: `continue_with`, `signin_with` or `signup_with`;
  - `theme`: `outline`, `filled_blue` or `filled_black`;
  - `size`: `small`, `medium` or `large`;
  - `redirect` must be on this site (`wp_validate_redirect`).
- **Bricks:** the "Google Login" element has the same four settings.

The button's language follows the page's locale.

### 5. Check

Log out, open a page with the button, and sign in with a Google account:
- **New email:** a customer is created and logged in.
- **Email of an existing customer:** that customer is logged in, and linked from then on.
- **Admin's email:** refused, with "Please log in with your password."

## Hosting notes

- **Page caches:** the button markup carries nothing personal, so pages with it can be cached. The login call goes
  to `/wp-json/`, which must not be cached. The usual nginx skip rules for `wp-json` cover it.
- **Front proxy header limit:** the login response sets the auth cookies. It deliberately doesn't call
  `wp_clear_auth_cookie()` first: that adds a dozen expiring `Set-Cookie` headers, and a proxy with a 4 KB header
  limit then answers 502 while the login actually succeeded.
- **Rate limit:** 20 login attempts per IP per 10-minute window (filter `sgl_max_attempts`), then HTTP 429.
- **Consent / privacy:** Google's script (`accounts.google.com/gsi/client`) loads only on pages that render the
  button. It may set Google's `g_state` cookie. List it in your cookie banner.

## Filters

| Filter | Default | Purpose |
|---|---|---|
| `sgl_auto_button` | `true` | Show the button after WooCommerce login forms |
| `sgl_allowed_roles` | `['customer','subscriber']` | Roles that may sign in with Google |
| `sgl_allow_registration` | WooCommerce's registration settings | Create new customers from Google sign-ins |
| `sgl_reset_password_on_link` | `true` | Replace the password the first time an existing account is linked |
| `sgl_max_attempts` | `20` | Login attempts per IP per 10 minutes |

## Security

Login is protected by:
- the token checks with Google: audience = your client ID, issuer, expiry, verified email;
- a one-time-use record of each token: Redis when a persistent object cache is present, else a transient;
- a same-site check: the request must be `application/json`, with `Origin` or `Referer` on this host. That stops
  another site from logging a visitor in (login CSRF);
- the per-IP rate limit.

## Troubleshooting

| Symptom | Cause |
|---|---|
| No button at all | `SGL_CLIENT_ID` missing, the visitor is logged in, or you're in the Bricks builder |
| "The Google button could not load…" | The browser or an extension blocked `accounts.google.com` |
| Google shows "origin not allowed" / error 400 | The site's origin isn't in the OAuth client's Authorized JavaScript origins |
| "Google sign-in failed." | The token was invalid or already used, the client ID doesn't match, or the request wasn't same-site JSON |
| "Registration is disabled." | WooCommerce registration is off for both My Account and checkout |
| "This account is already linked to another Google login." | The email's account is linked to a different Google account |
| Login works on the server but the browser stays logged out (502) | The proxy header limit; see Hosting notes |

## Authorship

Written by beltoft.net.
