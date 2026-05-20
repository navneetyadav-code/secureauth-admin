# SecureAuth Admin Documentation

This folder explains how the app is put together, how to install it, and how to keep it healthy. The goal is simple: anyone opening the repository should be able to understand the login flow without guessing through the PHP files one by one.

## What This App Does

SecureAuth Admin is a focused admin authentication gateway for a PHP and MySQL project. It handles:

- First-admin setup.
- Password login.
- Optional email OTP verification.
- Secure session creation and expiry.
- CSRF protection on POST requests.
- IP-based login throttling.
- Audit logging for important admin events.
- A small protected dashboard for checking auth activity.

The app is intentionally compact. It keeps the authentication surface small enough to review, test, and harden.

## Requirements

| Requirement | Notes |
| --- | --- |
| PHP | PHP 8 or newer is recommended. |
| MySQL or MariaDB | MySQL 8+ or MariaDB 10.5+ is a good target. |
| Apache | The included `.htaccess` protects sensitive folders when Apache allows overrides. |
| PDO MySQL | Required for database access. |
| SMTP account | Required only if email OTP is enabled for an admin. |

## Step 1: Put The Project In Place

Place the project folder where your PHP server can serve it.

For XAMPP on Windows, the path usually looks like this:

```powershell
C:\xampp\htdocs\secure-login
```

If the folder name changes, update `APP_URL` and `SESSION_COOKIE_PATH` in `.env` so cookies are scoped to the correct path.

## Step 2: Create The Environment File

Copy the safe template:

```powershell
Copy-Item .env.example .env
```

Then edit `.env`.

Important values:

| Key | What to put there |
| --- | --- |
| `APP_NAME` | The name shown in browser titles and emails. |
| `APP_ENV` | Use `production` for real use. |
| `APP_DEBUG` | Keep this `false` outside local debugging. |
| `APP_URL` | The exact URL of the app, for example `https://example.com/secure-login`. |
| `APP_KEY` | At least 32 random characters. Do not reuse a weak word or public value. |
| `APP_FORCE_HTTPS` | Set `true` when HTTPS is actually working. |
| `SETUP_TOKEN` | A private token for first-admin setup on public servers. |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | The database connection. |
| `SESSION_COOKIE_PATH` | The URL path where the app lives, for example `/secure-login`. |
| `MAIL_*` | SMTP settings for OTP email. |

Generate a strong `APP_KEY` with PHP:

```powershell
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Paste the output into `.env`.

## Step 3: Create The Database

Create a database and a dedicated database user. Do not use your MySQL root user for the app.

```sql
CREATE DATABASE `secure-login` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'secure_login_user'@'localhost' IDENTIFIED BY 'use-a-strong-password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, INDEX, ALTER ON `secure-login`.* TO 'secure_login_user'@'localhost';
FLUSH PRIVILEGES;
```

Import the schema:

```powershell
cmd /c "mysql --default-character-set=utf8mb4 -u secure_login_user -p secure-login < database\schema.sql"
```

The schema creates three tables:

| Table | Purpose |
| --- | --- |
| `admins` | Admin identity, password hash, OTP fields, status, and login metadata. |
| `login_attempts` | Failed login counters by IP address. |
| `admin_logs` | Audit trail for authentication and dashboard events. |

## Step 4: Run First-Admin Setup

Open:

```text
http://localhost/secure-login/setup.php
```

On a public server, set `SETUP_TOKEN` first and open:

```text
https://example.com/secure-login/setup.php?token=your-token
```

Setup creates the first admin only while the `admins` table is empty. Once an admin exists, the setup page stops accepting new accounts.

## Step 5: Log In

Open:

```text
http://localhost/secure-login/index.php
```

Enter the admin email and password you created during setup.

If `twofa_enabled` is `0`, a successful password login goes straight to `dashboard.php`.

If `twofa_enabled` is `1`, the app sends a six-digit email code and waits for the OTP before creating the authenticated admin session.

## Login Flow In Plain Words

1. `index.php` receives the login form.
2. The CSRF token is checked first.
3. The app checks whether the current IP is locked out.
4. The admin is loaded by email.
5. The submitted password is verified with `password_verify()`.
6. Failed logins are recorded in `login_attempts`.
7. Successful password login either starts OTP or completes login.
8. Completing login regenerates the session ID, rotates the CSRF token, stores admin session data, clears old OTP values, and records an audit event.
9. `dashboard.php` calls `require_admin()` before showing anything.

## File Guide

| File | What to look for |
| --- | --- |
| `config.php` | Shared bootstrap: env loading, error behavior, database connection, headers, sessions, CSRF, auth guard, audit logging, and escaping helper. |
| `index.php` | Login, lockout, password verification, optional OTP challenge, and login audit events. |
| `setup.php` | First-admin setup, setup token check, admin-table guard, and password hashing. |
| `dashboard.php` | Protected dashboard, safe asset selection, summary counters, and recent audit events. |
| `logout.php` | POST-only logout protected by CSRF validation. |
| `mail.php` | SMTP config validation and PHPMailer delivery. |
| `.htaccess` | Blocks direct access to sensitive folders and files on Apache. |
| `.gitignore` | Keeps local secrets, sessions, logs, and temporary files out of Git. |

## Enable Email OTP

First confirm SMTP is working in `.env`.

Then enable OTP for an admin:

```sql
UPDATE admins
SET twofa_enabled = 1
WHERE email = 'admin@example.com';
```

The app stores only a hash of the OTP, not the raw code. If the email cannot be sent, login is stopped and no admin session is created.

## Reset An Admin Password

Generate a new password hash:

```powershell
php -r '$p = readline("Password: "); echo password_hash($p, PASSWORD_DEFAULT), PHP_EOL;'
```

Update the admin row:

```sql
UPDATE admins
SET password_hash = 'paste-generated-hash-here'
WHERE email = 'admin@example.com';
```

Use this only when normal account recovery is not available.

## Clear A Login Lockout

Lockouts are stored by IP address:

```sql
SELECT * FROM login_attempts;
```

Clear one IP:

```sql
DELETE FROM login_attempts
WHERE ip = '127.0.0.1';
```

## Logs And Audits

| Location | Meaning |
| --- | --- |
| `admin_logs` table | Authentication and dashboard audit trail. |
| `storage/logs/app.log` | Server-side application errors and operational notes. |

The application log scrubs values whose keys look like passwords, secrets, tokens, OTPs, CSRF values, salts, or keys.

## Safe Repository Hygiene

Commit code, docs, schema templates, assets, and safe runtime libraries.

Do not commit:

- `.env`
- Runtime sessions.
- Application logs.
- Backups.
- Real database exports.
- SMTP passwords.
- Local temporary files.

Quick check before committing:

```powershell
git status --short
```

If `.env`, logs, sessions, or real data appear, stop and remove them from staging.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| "Application configuration is incomplete" | Required `.env` values are missing, or `APP_KEY` is shorter than 32 characters. |
| Login always redirects back | The session path may not be writable, or the cookie path may not match the deployed URL. |
| OTP never arrives | Check `MAIL_*` values and `storage/logs/app.log`. |
| Setup says it is complete | At least one admin already exists in the `admins` table. |
| Dashboard redirects to login | The session expired, the admin is inactive, or the browser fingerprint changed. |
| CSS or logo does not load | Confirm the project URL path and Apache document root. |

## Verification Checklist

Run syntax checks:

```powershell
php -l config.php
php -l index.php
php -l dashboard.php
php -l logout.php
php -l setup.php
php -l mail.php
```

Then test the real browser flow:

1. Setup creates the first admin.
2. Setup refuses to run after an admin exists.
3. A bad password shows a generic error.
4. Repeated bad passwords trigger lockout.
5. A good password creates a dashboard session.
6. Dashboard redirects when no session exists.
7. Logout requires a valid CSRF token.
8. OTP works when SMTP and `twofa_enabled` are enabled.
