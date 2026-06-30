# Redeyed Sentinel for WordPress

Add the [Redeyed Sentinel](https://redeyed.com) CAPTCHA and IP-reputation check to your WordPress **login**, **registration** and **comment** forms.

Sentinel is a self-hosted-friendly CAPTCHA + IP-reputation service. This plugin is **free to install and completely inert until you enter your keys** — no widget, no requests, no behaviour change until it is configured.

## Features

- Drop-in CAPTCHA widget on the WordPress login, registration and comment forms.
- Server-side token verification on submission. Your secret API key is sent only as the `X-Api-Key` header and is **never** printed in page markup.
- Handles both Sentinel response shapes — passes when `data.success === true` **or** top-level `success === true`.
- **Fail-open** when keys are missing: the plugin never blocks a site that hasn't been configured, and shows an admin notice that Sentinel is inactive.
- Per-form on/off switches under **Settings → Sentinel**.
- Loads a single small async script, only on pages with a protected form.

## Installation

1. Copy the `redeyed-wordpress` folder into `wp-content/plugins/`, or install it from the WordPress **Plugins → Add New** screen.
2. Activate **Redeyed Sentinel** from the **Plugins** screen.
3. Open **Settings → Sentinel**.
4. Enter your **Site Key** (Redeyed Lab → Developer → Sentinel Sites).
5. Enter your **API Key** (Developer → API Keys).
6. Optionally set a custom **Base URL** for self-hosted Sentinel (default `https://redeyed.com`).
7. Enable the forms you want to protect and save.

## How it works

### Rendering

On each page containing an enabled form, the plugin loads the Sentinel script once:

```html
<script src="https://redeyed.com/sentinel.js" async></script>
```

and renders the widget inside the form:

```html
<div class="sentinel-captcha" data-sitekey="YOUR_SITE_KEY"></div>
```

The widget injects a hidden input named `sentinel-token`.

### Verification

On submission, the plugin reads `$_POST['sentinel-token']` and verifies it server-side via `wp_remote_post`:

```
POST {BASE_URL}/api/v1/verify
X-Api-Key: {API_KEY}
Content-Type: application/json
Accept: application/json

{ "site_key": "{SITE_KEY}", "token": "<sentinel-token>" }
```

The submission passes only when the decoded response has `data.success === true` or `success === true`. Missing token, request error, or a non-passing result blocks the submission via `WP_Error` (login/registration) or `wp_die` (comments).

## Hooks used

| Purpose | Hook |
| --- | --- |
| Render on login | `login_form` |
| Render on registration | `register_form` |
| Render on comments | `comment_form_after_fields` |
| Verify login | `authenticate` (filter) |
| Verify registration | `registration_errors` (filter) |
| Verify comments | `preprocess_comment` |

## Requirements

- WordPress 5.8+
- PHP 7.4+

## License

MIT © 2026 Redeyed Corporation. See [LICENSE](LICENSE).
