# Redeyed Sentinel for WordPress

Add the [Redeyed Sentinel](https://redeyed.com) CAPTCHA and IP-reputation check to your WordPress **login**, **registration** and **comment** forms.

Sentinel is a self-hosted-friendly CAPTCHA + IP-reputation service. This plugin is **free to install and completely inert until you enter your keys** — no widget, no requests, no behaviour change until it is configured.

## Features

- Drop-in CAPTCHA widget on the WordPress login, registration and comment forms.
- Server-side token verification on submission. Your Secret Key is sent only in the server-side request body and is **never** printed in page markup.
- Handles both Sentinel response shapes — passes when `data.success === true` **or** top-level `success === true`.
- **Fail-open** when keys are missing: the plugin never blocks a site that hasn't been configured, and shows an admin notice that Sentinel is inactive.
- Per-form on/off switches under **Settings → Sentinel**.
- Optional widget customization (type, theme, colour scheme, minimum difficulty) — site-wide defaults, all optional.
- Loads a single small async script, only on pages with a protected form.

## Installation

1. Copy the `redeyed-wordpress` folder into `wp-content/plugins/`, or install it from the WordPress **Plugins → Add New** screen.
2. Activate **Redeyed Sentinel** from the **Plugins** screen.
3. Open **Settings → Sentinel**.
4. Enter your **Site Key** (Redeyed Lab → Sentinel → Sites).
5. Enter your **Secret Key** (Redeyed Lab → Sentinel → Sites; shown once, stays server-side).
6. Optionally set a custom **Base URL** for self-hosted Sentinel (default `https://redeyed.com`).
7. Enable the forms you want to protect and save.
8. (Optional) Set any of the **Widget Customization** fields to change how the widget looks and behaves.

## Widget customization

The **Widget Customization** section of **Settings → Sentinel** exposes four optional, site-wide defaults. Each is **completely optional** — leave any field blank to use the Sentinel default, and nothing changes from the original behaviour. When set, each value is rendered as a `data-*` attribute on the widget.

| Setting | `data-*` attribute | Example values |
| --- | --- | --- |
| Widget type | `data-widget` | `behavioral`, `checkbox`, `press_hold`, `image_pick`, … |
| Theme | `data-theme` | `auto`, `light`, `dark` |
| Colour scheme | `data-scheme` | any Sentinel colour-scheme name |
| Difficulty | `data-difficulty` | `easy`, `medium`, `hard`, `max`, or `1`–`6` |

**Difficulty only raises the challenge.** The value sets a *minimum* challenge strength above the adaptive baseline — a risky visitor is always challenged hard regardless of this setting. It cannot make a challenge easier than Sentinel's own risk assessment would.

For example, with **Theme** = `dark` and **Difficulty** = `hard`, the widget renders as:

```html
<div class="sentinel-captcha" data-sitekey="YOUR_SITE_KEY" data-theme="dark" data-difficulty="hard"></div>
```

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

Any [Widget Customization](#widget-customization) values you set are appended as `data-*` attributes on this div (only when non-empty), so the default markup above is unchanged unless you configure them.

The widget injects a hidden input named `sentinel-token`.

### Verification

On submission, the plugin reads `$_POST['sentinel-token']` and verifies it server-side via `wp_remote_post`:

```
POST {BASE_URL}/sentinel/siteverify
Content-Type: application/json
Accept: application/json

{ "secret": "{SECRET_KEY}", "response": "<sentinel-token>", "remoteip": "<client IP>" }
```

The `remoteip` field is optional. The submission passes only when the decoded response has `success === true` (the response also carries `outcome` and `score`). Missing token, request error, or a non-passing result blocks the submission via `WP_Error` (login/registration) or `wp_die` (comments).

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
