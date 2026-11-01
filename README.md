# Driver Portal

A minimal PHP 8.1+ and MySQL driver registration/login application. It uses PDO prepared statements, `password_hash()`/`password_verify()`, CSRF tokens, hardened session cookies, and database-backed login throttling.

## Setup

1. Create a MySQL user/database, then apply `database/schema.sql` (it creates `driver_portal`).
2. Set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` in the PHP process environment. `.env.example` documents the variables; PHP does not load it automatically.
3. Keep `SESSION_COOKIE_SECURE=true` and serve the app over HTTPS in production. For local HTTP-only development, set it to `false` locally, then restore `true` before deployment.
4. Configure the web server document root to `public/`; do not expose `app/` or `database/` as web content.
5. Ensure PHP has `pdo_mysql` and `mbstring` enabled.
6. Serve using your PHP web server, for example `php -S 127.0.0.1:8000 -t public` after configuring the environment.

New driver records always start with `verification_status = 'pending_verification'`. Login lets a driver see that status, but the dashboard explicitly treats pending accounts as not yet approved. Any future driver-only operation must independently require `verified`; hiding a link is not authorization.

## Driver verification options

1. **Manual document review:** Keep new accounts pending until an authorized reviewer checks the submitted license/identity documents against the driver's details, then updates the status to `verified` or `rejected`. Restrict status changes to an authenticated staff role and keep an audit trail.
2. **Third-party identity/license verification:** Integrate a licensed verification provider, obtain explicit consent, and validate the driver's license/identity through its API. Change the status only after a successful, auditable provider result; retain the pending state for failures or manual-review cases.

Choose the operational model before enabling access to dispatch, payments, or other driver services. Do not store raw identity-document images in this starter app.

## Security notes

- Login failures are limited to five attempts per 15-minute window by source IP and by IP/email pair. Successful authentication clears those buckets. The rate-limit table should be periodically cleaned of expired rows.
- Cookies are `HttpOnly`, `SameSite=Strict`, and `Secure` by default. HTTPS is required in production.
- Registration validates unique email and license values through database constraints. Passwords are never stored in plaintext.
- Configure web-server HTTPS, request-size limits, backups, database least-privilege access, and monitoring before production use.
