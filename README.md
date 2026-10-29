# GoAccess Plugin for LibreNMS

**v0.1.3** — a standalone LibreNMS Plugin v2 for displaying a private, static GoAccess HTML report inside LibreNMS. No LibreNMS core changes, database migrations, or fork branch changes are required.

v0.1.3 fixes PANELS label links opening a login page inside the report. A `srcdoc` frame inherits its parent URL as the base for relative links, so native `#panel` navigation can reload the authenticated LibreNMS route inside the sandbox. Fragment-only links now scroll within the report without changing its URL. GoAccess toggle handlers continue to run normally; no additional iframe privileges are granted. Browser regression tests cover real menu templates and Hogan engines from GoAccess 1.9.4 and the newer pinned source.

v0.1.2 fixes reports stuck at `No authentication provided.`: GoAccess displays this status before initializing its static dashboard. Its bundled Hogan template compiler uses `new Function()`, which requires CSP `unsafe-eval`. This is now allowed only in the report's sandboxed, opaque-origin iframe. Network connections and access to the parent LibreNMS page remain blocked. A real Chromium test compiles a template with the official GoAccess Hogan engine, verifies its rendered output, and checks parent DOM and network isolation.

v0.1.1 fixes the `Missing view` fallback on settings and report pages: Hook authorization now injects `Illuminate\Contracts\Auth\Authenticatable`, as in ExamplePlugin, so Laravel supplies the signed-in user instead of constructing an empty `App\Models\User`. Regression tests now exercise authorization through the actual container and `PluginManager::call()`.

## Features

- **Plugins → GoAccess**: report access for authenticated `admin` and `global-read` users.
- **Plugins → Plugin Admin → GoAccess Settings**: administrator-only HTML path configuration using LibreNMS's existing plugin settings database.
- Default: `/opt/librenms/app/Plugins/GoAccess/nginx-access.html`.
- Absolute Linux path validation, canonical target validation, readable regular HTML files only, and a 20 MiB read limit.
- HTML runs in an escaped `srcdoc` iframe with `sandbox="allow-scripts"`, without same-origin access. A restrictive CSP is inserted before the report content.

## Requirements and compatibility

Linux, PHP 8.2+, LibreNMS Plugin v2, and a self-contained static GoAccess HTML report. JavaScript and CSS must be embedded in the report. WebSocket/live reports and external assets are intentionally unsupported in v0.1.0.

The implementation follows the real `ExamplePlugin` and Hook classes at official LibreNMS commit [`d6da65b36d02c9b3ce5a63ccbc1a683ecc71100b`](https://github.com/librenms/librenms/tree/d6da65b36d02c9b3ce5a63ccbc1a683ecc71100b/app/Plugins):

| Hook | Contract used |
| --- | --- |
| `MenuEntryHook` | `authorize(Authenticatable $user): bool`, default menu view |
| `PageHook` | `authorize(Authenticatable $user): bool`, optional `data(array $settings = []): array` injected by final `handle()` |
| `SettingsHook` | `authorize(Authenticatable $user): bool`, `data(array $settings = []): array` |
| `PluginProvider` | One Hook implementation per top-level PHP file; helper code is under `Support/` |
| Plugin settings | `settings[report_path]`, `plugin.update` POST route, host CSRF and `plugin.admin` authorization |

**Settings validation behavior:** the upstream settings controller saves the `settings` array without calling a plugin validation Hook. This plugin therefore validates after saving (on the redirected settings page) and before every report read. An invalid path can remain in the database, but no report content is returned for it. No custom `validationRules()` Hook or patched controller is assumed.

## Install

Run on the LibreNMS server, adjusting the installation location if needed. These commands create a separate checkout and link only the plugin folder. They do not switch or modify the LibreNMS repository branch.

```sh
sudo install -d -o librenms -g librenms /opt/librenms-plugins
sudo -u librenms git clone --branch v0.1.3 --depth 1 \
  https://github.com/NightowlKr/GoAccess-Plugin-for-LibreNMS.git \
  /opt/librenms-plugins/GoAccess-Plugin-for-LibreNMS
```

Before linking, inspect `/opt/librenms/app/Plugins/GoAccess`. If it already exists (including the default report), back it up and reconcile it manually. Do not overwrite it or use `ln -sf`. Once the destination does not exist:

```sh
sudo -u librenms ln -sT \
  /opt/librenms-plugins/GoAccess-Plugin-for-LibreNMS/GoAccess \
  /opt/librenms/app/Plugins/GoAccess
```

1. Generate the GoAccess report at the default path, or preferably at a separate persistent location such as `/var/lib/goaccess/nginx-access.html`. Configure your existing GoAccess job to write it; this plugin does not run GoAccess.
2. Grant the PHP-FPM worker user read access to the file and traversal access to its parent directories. Use an appropriate shared group; do not use world-writable permissions.
3. Sign in to LibreNMS as an administrator. Open **Plugins → Plugin Admin**, enable **GoAccess** if needed, and open **Settings**.
4. Enter the absolute report path, click **Save**, and check the validation message.
5. Open **Plugins → GoAccess**. If the plugin is not discovered, consult your installation's normal PHP/opcache reload procedure. No new route needs to be registered.

The symlink may appear as an untracked item in the LibreNMS checkout. Do not add it to your LibreNMS fork. Reports are excluded from this plugin repository by `.gitignore`.

## Security and operational boundaries

- Configuration is restricted by LibreNMS's `plugin.admin` policy. Reports contain potentially sensitive traffic details, so ordinary per-device users are denied; access requires `global-read` or administrator privileges.
- No unauthenticated PHP endpoint, static report URL, or public asset route is installed. Keep reports outside the web server's public document root and do not create an nginx/Apache alias exposing them.
- Only administrator-configured `.html`/`.htm` files are accepted. Traversal segments, control characters, backslashes, URL/stream wrappers and non-HTML symlink targets are rejected. Checks are repeated on every view, and reads are bounded to 20 MiB.
- The iframe cannot access LibreNMS cookies, DOM, or browser storage. CSP blocks network assets, fetch/WebSocket connections, nested frames, objects, forms, and base URLs. Inline report scripts/styles and dynamic JavaScript compilation (`unsafe-eval`, required by GoAccess's Hogan engine) are allowed inside this isolated frame. LibreNMS's parent-page CSP is not modified. A stricter CSP imposed by your reverse proxy may still block report initialization; check the browser console in that case.
- This is isolation, **not HTML sanitization**. Only configure trusted GoAccess output in directories writable by trusted operators. Sandbox/CSP does not guarantee that hostile content cannot navigate its own frame. Do not point it at arbitrary uploaded HTML. No filesystem allowlist is imposed beyond the administrator-selected path.
- Symlinked plugin directories are supported. Protect the report's directories from untrusted writers; validation does not eliminate all filesystem race conditions against a local attacker who can replace directories.
- Write reports atomically (temporary file then rename) to avoid readers seeing partially written output. Report refresh is manual. Downloads, popups, forms and external fonts/assets may be unavailable due to sandbox restrictions.

## Update and rollback

Keep generated reports in `/var/lib/goaccess` so releases can be replaced independently. From the separate checkout:

```sh
cd /opt/librenms-plugins/GoAccess-Plugin-for-LibreNMS
sudo -u librenms git fetch --tags origin
sudo -u librenms git checkout --detach v0.1.3
```

Replace the tag with a reviewed newer release to update; check out the prior tag to roll back. Do not use a hard reset or clean command on a directory containing reports. Settings remain in LibreNMS's existing `plugins` table. Disable the plugin through Plugin Admin before uninstalling; remove only its symlink after confirming the target.

## Verification

Given a LibreNMS checkout with Composer dependencies installed:

```sh
php tests/run.php /path/to/librenms
find GoAccess tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

The isolated harness uses real LibreNMS Hook classes and final `handle()` methods, Laravel Gate with role objects, Blade, the Plugin model, PluginManager, and an in-memory SQLite database. It checks role access, settings persistence, injected settings, invalid paths, oversized/empty files, HTML escaping and iframe policy. Linux also checks symlink target rejection. It does not modify the referenced checkout or connect to a production database.

CI runs against the pinned upstream commit on PHP 8.2 and 8.4. It also runs `tests/browser.cjs` in Chromium using the Hogan engine from GoAccess commit `0dda4e3cb0b3f38e43ec1f2e6dce18e2bbfb5956`. This reproduces static template initialization inside the report iframe; it is not a complete GoAccess dashboard or production web-server test. Before production use, confirm on your server that guests are sent to login, ordinary users see no report, global-read users can view but not configure it, administrators can save paths, and your generated GoAccess charts render correctly.

The browser checks also cover GoAccess 1.9.4 (`f79fb60df47b1c637174d61c67a207432698dce4`), using its real PANELS menu template to test toggle clicks, nested icon/label link clicks and the Overall `#` link. Fragment navigation must scroll inside the report without loading the LibreNMS login page; network connections and parent DOM access remain blocked. GWSocket connections are still outside the supported static-report feature set.

## License

GPL-3.0-or-later; see [LICENSE](LICENSE). LibreNMS declares `GPL-3.0-or-later` in its `composer.json`, and the referenced ExamplePlugin/Hook sources carry the same GPL version-or-later notice. This plugin uses the same license for compatibility. GoAccess binaries, generated reports and third-party assets are not distributed in this repository; their existing licenses remain applicable.

Copyright (c) 2026 GoAccess Plugin for LibreNMS contributors.
