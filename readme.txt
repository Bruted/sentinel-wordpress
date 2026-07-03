=== Redeyed Sentinel ===
Contributors: bruted
Tags: captcha, spam, security, login, comments
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.2
License: MIT
License URI: https://opensource.org/licenses/MIT

Free Redeyed Sentinel CAPTCHA and IP-reputation check for your WordPress login, registration and comment forms. Inert until keys are set.

== Description ==

Redeyed Sentinel is a free, self-hosted-friendly CAPTCHA and IP-reputation service for WordPress. It protects the three forms bots love most — login, registration and comments — without tracking your visitors or slowing your site down.

The plugin is **free to install and does nothing until you enter your keys**. With no Site Key and Secret Key configured, Sentinel stays completely inert: no widget is rendered, no requests are made, and your forms behave exactly as before.

**What you get**

* Drop-in CAPTCHA widget on the WordPress login, registration and comment forms.
* Server-side verification on submission — tokens are checked against Sentinel using your site's Secret Key, which is never exposed in page markup.
* Fail-open by design: if your keys are missing the plugin will not block anyone, and an admin notice reminds you that Sentinel is inactive.
* Simple settings screen under Settings → Sentinel with per-form on/off switches.
* Optional widget customization — set a site-wide widget type, theme, colour scheme and minimum difficulty. Every field is optional and off by default.
* Lightweight: one small async script, loaded only on the pages where a protected form appears.

**Privacy**

Your Secret Key is sent only from your server to the Sentinel verification endpoint (`/sentinel/siteverify`). It is never printed in HTML. The public Site Key is the only key that appears on the page, which is exactly what it is designed for.

== Installation ==

1. Upload the `redeyed-sentinel` folder to the `/wp-content/plugins/` directory, or install the plugin through the **Plugins → Add New** screen in WordPress.
2. Activate the plugin through the **Plugins** screen. The plugin is installed free and is inactive until you add your keys.
3. Go to **Settings → Sentinel**.
4. In the Redeyed Lab, open **Sentinel → Sites**, create a site, and copy its **Site Key** and **Secret Key** (the Secret Key is shown once).
5. Paste the **Site Key** and **Secret Key** into the plugin settings.
6. (Optional) Change the **Base URL** only if you run a self-hosted Sentinel deployment. The default is `https://redeyed.com`.
7. Tick the forms you want to protect — **Login**, **Registration**, and/or **Comments** — and save.
8. (Optional) Fill in any of the **Widget Customization** fields to change how the widget looks and behaves.

That's it. Sentinel activates the moment both keys are present and at least one form is enabled.

== Frequently Asked Questions ==

= Does the plugin do anything before I enter my keys? =

No. Without both a Site Key and a Secret Key, Sentinel is completely inert. No widget is rendered and no verification requests are made. It is safe to install and leave unconfigured.

= Where do I get my keys? =

Both keys come from the Redeyed Lab → **Sentinel → Sites**. Each site has a public **Site Key** (renders the widget) and a private **Secret Key** (used only server-side to verify). The Secret Key is shown once when you create the site — copy it then.

= Is my Secret Key exposed on the page? =

Never. The Secret Key is sent only from your server to the Sentinel verification endpoint (`/sentinel/siteverify`). It is never written into your HTML and is never displayed back on the settings screen once saved.

= What happens if Sentinel can't be reached when someone submits a form? =

If the verification request fails or the token is missing while Sentinel is configured, that submission is blocked. If your keys are not configured at all, the plugin fails open and never blocks a submission.

= Can I use this with a self-hosted Sentinel instance? =

Yes. Change the **Base URL** on the settings screen to point at your own Sentinel deployment.

= Which forms are supported? =

The WordPress login form, the user registration form, and the comment form. Each can be enabled independently.

= Can I customize how the widget looks? =

Yes. The **Widget Customization** section of Settings → Sentinel adds four optional, site-wide defaults, each rendered as a `data-*` attribute on the widget only when you set it:

* **Widget type** (`data-widget`) — e.g. `behavioral`, `checkbox`, `press_hold`, `image_pick`.
* **Theme** (`data-theme`) — `auto`, `light` or `dark`.
* **Colour scheme** (`data-scheme`) — a Sentinel colour-scheme name.
* **Difficulty** (`data-difficulty`) — `easy`, `medium`, `hard`, `max`, or `1`–`6`.

Every field is optional; leave any blank to use the Sentinel default. **Difficulty only raises the challenge** — it sets a minimum strength above the adaptive baseline, and a risky visitor is always challenged hard regardless. With Theme = `dark` and Difficulty = `hard` the widget renders as `<div class="sentinel-captcha" data-sitekey="…" data-theme="dark" data-difficulty="hard"></div>`.

== Screenshots ==

1. The Settings → Sentinel configuration screen.
2. The Sentinel CAPTCHA widget on the login form.

== Changelog ==

= 1.0.2 =
* Added optional widget customization: **Widget type**, **Theme**, **Colour scheme** and **Difficulty** settings, rendered as `data-widget` / `data-theme` / `data-scheme` / `data-difficulty`. Difficulty only *raises* challenge strength above the adaptive baseline.

= 1.0.1 =
* Verification now uses the site's **Secret Key** against the public `/sentinel/siteverify` endpoint — no developer API key required.
* Renamed the "API Key" setting to "Secret Key" to match the keys shown in the Lab.

= 1.0.0 =
* Initial release.
* CAPTCHA rendering and server-side verification for login, registration and comment forms.
* Settings screen with Site Key, Secret Key, Base URL and per-form toggles.
* Fail-open behaviour and admin notice when keys are missing.

== Upgrade Notice ==

= 1.0.2 =
Optional widget customization — set the widget type, theme, colour scheme and difficulty from Settings → Sentinel.

= 1.0.1 =
Verification now uses your site's Secret Key with /sentinel/siteverify — no separate API key needed.

= 1.0.0 =
Initial release of Redeyed Sentinel.
