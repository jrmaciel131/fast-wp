# Fast WP

**English** · [Português](README.pt-BR.md)

A free WordPress performance plugin built for Elementor sites. No paid tier, no external services: everything runs on your own server, and every feature has its own on/off checkbox.

## Features

| Area | What it does |
|---|---|
| Cache | Page cache on disk (served by Apache or PHP), GZIP, browser caching, preloading, automatic purge when you edit with Elementor. Ad URLs (`utm_*`, `gclid`, `fbclid`, `gbraid`...) still use the cache. |
| CSS | Minification and **unused CSS removal** per page, with a safelist for Elementor menus, popups and effects. The full CSS loads after the first interaction as a safety net. |
| JavaScript | **Delay until interaction**, defer (jQuery included, keeping script order), optional minification. Pixels and GTM are not delayed by default; PixelYourSite is detected and jQuery is kept with it. |
| Images | Lazy load, main image (LCP) with high priority and preload, YouTube thumbnail instead of the player, lazy background images, WebP created on the server. |
| Fonts | Self-hosted Google Fonts, `font-display: swap`, optional preload of the main font. |
| Cleanup | Emojis, embeds, head tags, XML-RPC, Dashicons, Gutenberg CSS, jQuery Migrate, Heartbeat. |
| Other | CDN, database cleanup, settings export/import between sites, automatic updates from GitHub, English and Portuguese interface. |

## Safety

- **Test mode:** after installing, visitors do not get the optimizations yet. Open the site with `?fwp_test=1` to see the optimized version (the PageSpeed button in the dashboard already adds it). `?fwp_off=1` shows the page without any optimization.
- **Fail-safe:** if an optimization fails, the original page is served and the error is shown in the dashboard.
- **Self-test:** after every update the plugin opens the home page. If it finds an error, it turns safe mode on by itself and switches the optimizations off.
- **Emergency switch:** `define( 'FAST_WP_DISABLE', true );` in `wp-config.php` turns everything off immediately.
- **Clean deactivation:** removes the `.htaccess` rules, `advanced-cache.php` and the `WP_CACHE` line from `wp-config.php`.

## Installation

1. Deactivate any other cache/optimization plugin (WP Fastest Cache, WP Rocket...) and clear its cache.
2. Download `fast-wp.zip` from the [latest release](../../releases/latest) and upload it in Plugins > Add New > Upload Plugin.
3. In the **Fast WP** menu, use **Test on PageSpeed** and browse the site with `?fwp_test=1`.
4. When everything looks right, click **Go live for visitors**.

Recommended in Elementor (Elementor > Settings > Features): enable "Lazy Load Background Images".

## Language

The plugin follows the WordPress language by default. To force a language, go to **Fast WP > General > Plugin language**. Translations live in `languages/{locale}.php`; to add a language, copy `languages/pt_BR.php`, translate the values and add the locale to `I18n::LANGUAGES`.

## Updates from GitHub

Sites only update when a new **release** is published (a `v*` tag). A regular commit does not update anything.

1. The repository is set in `FAST_WP_GITHUB_REPO` in `fast-wp.php` (this project: `jrmaciel131/fast-wp`). In **Fast WP > General** you choose the update channel and turn automatic updates on or off.
2. To ship a version: change the version in the header and in `FAST_WP_VERSION` in `fast-wp.php`, commit, then push a tag:
   - `v0.2.0-beta.1` → pre-release: only sites on the **Beta** channel get it.
   - `v0.2.0` → stable: every site gets it.
3. The GitHub Action checks the syntax (PHP 7.4 and 8.3), runs the tests, builds `fast-wp.zip` and publishes the release.
4. Each site checks GitHub every 6 hours and WordPress installs the update automatically.

Tip: keep one low-risk site on the **Beta** channel and the others on **Stable**.

## Development

```bash
php tests/run.php            # automated tests (no WordPress needed)
python bin/build-zip.py      # builds build/fast-wp.zip
```

Test with a real page (copy the page HTML and its CSS files into `tests/fixtures/`):

```bash
FWP_TEST_ROOT=tests/fixtures/site FWP_TEST_HOME=https://www.yoursite.com php tests/real-site.php tests/fixtures/home.html
```

## Structure

```
fast-wp.php                     plugin header and loading
languages/pt_BR.php             Portuguese translation
includes/class-plugin.php       hooks, activation, deactivation, schedules
includes/class-settings.php     tabs, fields, defaults, presets
includes/class-i18n.php         translations
includes/class-request.php      cache rules (also used by advanced-cache.php)
includes/class-cache.php        capture, storage, purge and preload of the cache
includes/class-htaccess.php     Apache rules
includes/class-optimizer.php    runs the optimizations (fail-safe)
includes/class-css.php          minification and unused CSS
includes/class-used-css.php     decides which CSS rules a page uses
includes/class-css-parser.php   CSS parser
includes/class-js.php           delay, defer and the browser loader
includes/class-media.php        lazy load, LCP, YouTube, backgrounds, WebP
includes/class-fonts.php        self-hosted Google Fonts and font-display
includes/class-webp.php         WebP conversion on the server
includes/class-cleanup.php      WordPress cleanup
includes/class-database.php     database cleanup
includes/class-self-test.php    self-test
includes/class-updater.php      updates from GitHub
includes/class-admin.php        settings screen, notices and admin bar
```

## License

[GPL-2.0-or-later](LICENSE), the same license as WordPress.
