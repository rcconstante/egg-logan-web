# Egglogan

Egg inventory, FIFO stock rotation, sales, receipts, and demand forecasting for a tray egg business in Naic, Cavite.

## Features

- Public landing page with egg sizes, location map, and privacy notice.
- Administrator and staff accounts, signup approval, and role-based access.
- Batch receiving, production and expiry dates, damage recording, and stock alerts.
- Reusable QR labels by egg variant, scan lookup, and print selection.
- Quick Sale with independent sale entry, payment summary, and FIFO panels.
- Receipts, administrator voiding, reports, and CSV exports.
- KNN demand forecasts, model evaluation, and retraining controls.
- Pricing, shelf life, business settings, stock coverage, and maintenance tools.

## Requirements

- PHP 8.0 or newer with PDO MySQL, JSON, and GD enabled.
- MariaDB 10.4 or newer (the bundled SQL uses MariaDB-compatible routines and events).
- Apache with `mod_rewrite`, `.htaccess` overrides, and the PHP module; XAMPP is suitable for local setup.
- A modern browser. Camera scanning needs permission and HTTPS, or localhost during development.

The application uses plain PHP, JavaScript, and CSS. There is no Node build step. The QR library is bundled in `vendor/phpqrcode/`.

## Local setup with XAMPP

1. Clone into the Apache document root using the folder name **Egglogan**:

   ```powershell
   git clone https://github.com/rcconstante/egg-logan-web.git C:\xampp\htdocs\Egglogan
   ```

2. Start Apache and MySQL in the XAMPP control panel.
3. Import `egg_storage_db.sql` through phpMyAdmin. This creates the database, tables, reference egg sizes, views, settings, routines, and events. Import it into a fresh database; it is not an upgrade script for an existing installation.
4. Visit [http://localhost/Egglogan/](http://localhost/Egglogan/).
5. Create your first account through **Sign In → Sign Up**, using your own password. The account starts pending approval. For this initial setup only, use phpMyAdmin to promote that account:

   ```sql
   UPDATE egg_storage_db.staff_users
   SET role = 'admin', is_active = 1, is_approved = 1
   WHERE username = 'YOUR_CHOSEN_USERNAME';
   ```

6. Sign in and review Pricing, Shelf Life, System, and Stock Coverage under Settings. Create and approve subsequent accounts through Staff Management.

No default login credentials are shipped. The SQL file includes `predicted_sales` and the current reference columns; do not blindly run every migration afterward. Files in `migrations/` are targeted upgrades for older databases and may fail if their columns already exist.

The application assumes `/Egglogan/` as its URL base in `.htaccess`, service worker, and manifest. Keep this folder name for local setup. A different deployment path requires reviewing those references.

## Configuration

`config/database.php` reads environment variables. The application does **not** automatically load `.env` files; configure variables in Apache or the process environment.

| Variable | Default | Purpose |
| --- | --- | --- |
| `DB_HOST` | `localhost` | Database host |
| `DB_NAME` | `egg_storage_db` | Database name |
| `DB_USER` | `root` | Database user; use a dedicated account outside local development |
| `DB_PASS` | empty | Database password |
| `APP_TIMEZONE` | stored setting, otherwise `Asia/Manila` | Optional time-zone override |
| `EGGLOGAN_BACKUP_DIR` | private user directory under `Egglogan/backups` | Writable backup directory outside the web document root |

Use HTTPS and server-managed secrets on a hosted installation. Keep backups outside the web root. Restrict database privileges for the deployed application; schema import and the audit runner need additional privileges. MariaDB events require the event scheduler to be enabled if you use scheduled database jobs.

## Daily workflow

1. Receive stock with **Inventory → New Batch**.
2. Print reusable variant labels from Inventory and place them beside the relevant trays.
3. Scan a label to view current trays, production/expiry dates, and remaining eggs.
4. Use **Quick Sale** and FIFO recommendations to sell eligible stock.
5. Review receipts, reports, expiry alerts, and forecasts. Forecasts require actual sales history; zero-data states are expected on a new installation.

## Validation

From the project directory:

```powershell
C:\xampp\php\php.exe tests\run_backend_audit.php
```

The runner creates isolated audit databases and test accounts, exercises backend workflows and HTTP endpoints, and removes its fixtures afterward. It requires a working source database and permissions to create/drop databases, views, and tables, plus PHP process functions and an available loopback port. Run it locally with an appropriate test database user.

The backend audit recorded 86 passing checks, including stock allocation, concurrent sales/voids, permissions, account approval, CSRF, QR lookup, settings validation, backup restoration, and exports. See [the audit report](docs/backend-audit.md) for coverage and limits. UI layouts were also checked in the browser at desktop and mobile widths. Physical camera and printer output still need testing on the client's devices.

## Project structure

```text
api/          Authenticated action and reporting endpoints
assets/       Styles, browser scripts, icons, and images
classes/      Inventory, sales, QR, forecasting, and settings services
config/       Database connection configuration
includes/     Authentication, validation, and shared UI
pages/        Dashboard and authenticated application screens
verify/       QR/receipt verification routes
migrations/   Targeted database upgrade scripts
tests/        Backend and QR regression checks
docs/         Audit report and model reference documents
```

Local backups, runtime uploads, logs, and secret configuration are excluded from Git. Never commit a live database dump or customer/account records.
