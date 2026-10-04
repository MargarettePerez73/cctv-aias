# CCTV Media Management System

Plain PHP 8 + MySQL (MariaDB) app for XAMPP. No Node, no build step: pages are server-rendered PHP,
styles are plain CSS, and a little vanilla JavaScript handles what only a browser can do (webcams,
motion analysis, alert sounds).

## Running

- Apache (XAMPP): the project folder must sit under `htdocs`, e.g. `C:\xampp1\htdocs\cctv`.
- Built-in server: `php -S localhost:8000 router.php` from the project root. `router.php` blocks the
  private folders the same way the `.htaccess` files do under Apache.
- Camera access requires `localhost` or HTTPS.

## Structure

- `*.php` in the root — one file per page (`index.php`, `cameras.php`, `events.php`, …). Each starts with
  `require __DIR__ . '/app/bootstrap.php'`, `connect($config)` and `require_page($db)`, handles its own
  POST with `handle_post()` (CSRF + flash + redirect), then renders between `render_header()` and
  `render_footer()`.
- `app/` (not web-accessible)
  - `bootstrap.php` — config, session, `connect()` (also runs migrations)
  - `helpers.php` — input, database, auth/CSRF/flash, formatting, file storage
  - `services.php` — business logic shared by pages and the API (events, alerts, RFID, users, cameras,
    recordings, retention, settings, audit)
  - `permissions.php` — `NAV` (sidebar + page roles) and `ABILITIES`; `can()`, `authorize()`, `require_page()`
  - `layout.php` — `icon()`, header/sidebar/footer, UI fragments (`pill()`, `empty_state()`, `camera_tile()`, …)
  - `migrations.php` — idempotent schema upgrades applied automatically
  - `config.example.php` — copy to `config.php` (git-ignored) to override settings
- `api/index.php` — JSON endpoints used by the browser scripts only (station uploads, heartbeat, frame
  relay, alerts polling, panic, siren reset, recording/snapshot streaming). POSTs need the `X-CSRF-Token` header.
- `assets/css/app.css`, `assets/js/app.js` (all pages), `assets/js/station.js` (Live cameras page)
- `database/schema.sql` — base schema for a new install
- `storage/` — recordings, snapshots, relay frames (git-ignored, never served directly)

## Conventions

- Escape all output with `e()`. Use prepared statements (`row()`, `rows()`, `scalar()`).
- Every permission check lives on the server: `require_page()` for pages, `authorize()` in form handlers,
  `api_user()` in the API. Hiding a button is not a permission check.
- Audit sensitive actions with `audit()`. `audit_logs` rejects UPDATE/DELETE, so users are deactivated,
  never deleted.
- Schema changes go in `app/migrations.php` as a new keyed entry; never edit an applied migration.
