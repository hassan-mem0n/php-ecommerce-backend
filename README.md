# PHP backend (converted from Node/Express + MongoDB)

Requirements: PHP 8.0+ (curl + pdo_mysql), MySQL 5.7+/MariaDB, Apache with mod_rewrite (cPanel hosting is fine).

## Setup
1. Create a MySQL database and import `schema.sql`.
2. Copy `.env.example` to `.env` and fill in DB, JWT_SECRET, ELAVON_ACCESS_TOKEN.
3. Upload this whole folder to your host (e.g. `public_html/backendnew/`).
4. Register a user through the API, then make it admin:
   `UPDATE users SET role='admin' WHERE email='you@example.com';`

The API URLs and JSON responses are the same as before (`/api/auth/login`, `/api/services`, ...).
The old `/backendnew` prefix is handled automatically.

## Files
- `index.php` routes | `core.php` DB, JWT, auth, helpers | `controllers/` endpoints | `services/elavon.php` payments
