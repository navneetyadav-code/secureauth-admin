<p align="center">
  <img src="assets/images/logo_main.png" alt="SecureAuth Admin" width="110">
</p>

<h1 align="center">SecureAuth Admin</h1>

<p align="center">
  A lightweight PHP/MySQL admin authentication gateway with secure sessions, CSRF protection, OTP verification, login throttling, and audit logs.
</p>

<p align="center">
  <a href="https://github.com/navneetyadav-code/secureauth-admin/releases/tag/v1.0.0"><img alt="Release" src="https://img.shields.io/github/v/release/navneetyadav-code/secureauth-admin?style=for-the-badge&color=0f766e"></a>
  <a href="LICENSE"><img alt="License: MIT" src="https://img.shields.io/badge/License-MIT-f59e0b?style=for-the-badge"></a>
  <img alt="PHP 8+" src="https://img.shields.io/badge/PHP-8%2B-777BB4?style=for-the-badge&logo=php&logoColor=white">
  <img alt="MySQL" src="https://img.shields.io/badge/MySQL-8%2B-00758F?style=for-the-badge&logo=mysql&logoColor=white">
  <img alt="Apache" src="https://img.shields.io/badge/Apache-.htaccess-D22128?style=for-the-badge&logo=apache&logoColor=white">
  <a href="docs/SECURITY.md"><img alt="Security reviewed" src="https://img.shields.io/badge/Security-Reviewed-0F766E?style=for-the-badge"></a>
  <a href="https://github.com/navneetyadav-code/secureauth-admin/actions"><img alt="PHP Syntax Check" src="https://img.shields.io/github/actions/workflow/status/navneetyadav-code/secureauth-admin/php-syntax.yml?branch=main&style=for-the-badge&label=PHP%20syntax"></a>
</p>

SecureAuth Admin is a small, serious authentication layer for PHP projects. It keeps the surface area easy to review while covering the parts that matter most: password hashing, strict cookies, session expiry, CSRF tokens, optional email OTP verification, IP lockouts, safe logging, and a first-admin setup flow.

It is not trying to be a giant framework. It is the front door.

## Why Developers Use It

- Drop-in PHP admin login system for dashboards, panels, internal tools, and small SaaS back offices.
- Secure login starter with PDO, MySQL, CSRF defense, OTP email verification, session hardening, and audit logs already wired together.
- Beginner-friendly XAMPP setup, but written with production habits like `.env` secrets, Apache deny rules, and safe logging.
- MIT licensed, so you can use it in personal, commercial, client, and learning projects.

## Keywords

`php authentication`, `php login system`, `secure admin login`, `php mysql login`, `admin dashboard login`, `csrf protection`, `two factor authentication`, `email otp`, `pdo mysql`, `xampp php project`, `php security`, `audit logs`, `login throttling`, `secure session management`

## Quick Links

| Document | What it covers |
| --- | --- |
| [Project documentation](docs/README.md) | Step-by-step setup, configuration, login flow, operations, and troubleshooting. |
| [Security review](docs/SECURITY.md) | Code-level security coverage, remaining responsibilities, and manual test checklist. |
| [Contributing guide](CONTRIBUTING.md) | How to report bugs, request features, and send clean pull requests. |
| [Changelog](CHANGELOG.md) | Version history for releases. |
| [GitHub SEO checklist](docs/GITHUB_SEO.md) | Repository topics, description, social preview, and engagement settings. |
| [Database schema](database/schema.sql) | Tables for admins, login attempts, and audit logs. |
| [.env example](.env.example) | Safe template for local and server configuration. |

## What You Get

| Area | Built-in behavior |
| --- | --- |
| Passwords | Uses PHP `password_hash()` and `password_verify()` with automatic rehash support. |
| Sessions | Uses strict cookies, `HttpOnly`, `SameSite=Strict`, session ID regeneration, idle timeout, absolute timeout, and user-agent fingerprinting. |
| CSRF | Protects every POST flow with random tokens and `hash_equals()`. |
| Login abuse | Tracks failed attempts by IP and applies a lockout window. |
| 2FA | Supports optional email OTP with hashed codes, expiry, and attempt limits. |
| Audit trail | Records login, logout, OTP, setup, dashboard, lockout, and CSRF events. |
| Headers | Sends CSP, frame protection, MIME sniffing protection, referrer policy, permissions policy, and no-store cache headers. |
| Setup | Creates the first admin only while the admin table is empty. |

## Project Map

| Path | Purpose |
| --- | --- |
| `config.php` | Environment loading, PDO setup, secure sessions, headers, CSRF helpers, auth guard, logging, and shared helpers. |
| `index.php` | Login screen, credential verification, lockout handling, and OTP challenge. |
| `dashboard.php` | Protected dashboard with auth health stats and recent audit activity. |
| `logout.php` | CSRF-protected logout with full session destruction. |
| `setup.php` | First-admin setup screen. Locked after the first admin exists. |
| `mail.php` | PHPMailer SMTP delivery for admin OTP email. |
| `assets/` | CSS, JavaScript, and the project logo. |
| `database/schema.sql` | The schema this app expects. |
| `storage/` | Runtime logs and sessions. Kept out of Git except `.gitkeep` files. |

## Fast Local Setup

1. Put the folder inside your web server document root.

   For XAMPP on Windows, this project is commonly served from:

   ```powershell
   C:\xampp\htdocs\secure-login
   ```

2. Copy the environment template.

   ```powershell
   Copy-Item .env.example .env
   ```

3. Open `.env` and fill the real values.

   Set `APP_URL`, create a strong `APP_KEY`, confirm the database values, and adjust `SESSION_COOKIE_PATH` if the folder name changes.

4. Create the database and import the schema.

   ```sql
   CREATE DATABASE `secure-login` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'secure_login_user'@'localhost' IDENTIFIED BY 'use-a-strong-password';
   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, INDEX, ALTER ON `secure-login`.* TO 'secure_login_user'@'localhost';
   FLUSH PRIVILEGES;
   ```

   ```powershell
   cmd /c "mysql --default-character-set=utf8mb4 -u secure_login_user -p secure-login < database\schema.sql"
   ```

5. Open `setup.php` in the browser and create the first admin.

   Local requests can run setup without a setup token. On a public server, set `SETUP_TOKEN` in `.env` first and open `setup.php?token=your-token`.

6. Log in at `index.php`.

   After the first admin exists, setup closes itself automatically.

## Configuration Checklist

| Key | Why it matters |
| --- | --- |
| `APP_KEY` | Must be at least 32 random characters. Used for keyed hashes and session fingerprinting. |
| `APP_URL` | Should match the exact URL where the app is served. |
| `APP_FORCE_HTTPS` | Use `true` only when the app is really served through HTTPS. |
| `DB_*` | Use a dedicated database user instead of a root account. |
| `SESSION_COOKIE_PATH` | Set this to the deployed folder path, such as `/secure-login`. |
| `SETUP_TOKEN` | Required for safer first-admin setup on public servers. |
| `MAIL_*` | Required only when an admin has email OTP enabled. |

## Security At A Glance

The important security behavior is documented in [docs/SECURITY.md](docs/SECURITY.md). In short, the app is designed to fail closed, avoid leaking secrets, and make authentication events visible through audit logs.

Before sharing the repository, keep these files out of commits:

```text
.env
storage/logs/*
storage/sessions/*
backups
real database dumps
temporary files
```

The included `.gitignore` already handles the common cases. Still, review `git status --short` before every commit.

## Good First Contributions

Useful improvements are welcome:

1. Stronger first-admin password rules.
2. Account-level throttling in addition to IP throttling.
3. Admin management screen for enabling OTP without SQL.
4. Automated tests for login, CSRF, lockout, OTP expiry, and setup lockout.
5. Translations for setup and login screens.

## Verify The App

```powershell
php -l config.php
php -l index.php
php -l dashboard.php
php -l logout.php
php -l setup.php
php -l mail.php
```

Then test these flows in the browser:

1. Wrong password shows a generic error and increments the lockout counter.
2. Correct password creates a new authenticated session.
3. Dashboard redirects to login when no session exists.
4. Logout works only through POST with a valid CSRF token.
5. OTP login works when SMTP is configured and `twofa_enabled = 1`.

## License

Released under the [MIT License](LICENSE). You can use, copy, modify, publish, distribute, sublicense, and sell copies of this project as long as the license notice stays included.
