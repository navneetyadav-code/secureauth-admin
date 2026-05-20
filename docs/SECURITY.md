# Security Review

This document reviews the security behavior currently implemented in the codebase. It is written as a practical checklist for maintainers: what is already covered, where it lives, and what still depends on deployment choices.

## Review Summary

The app has a solid security baseline for a compact PHP admin gateway. The main controls are in place: password hashing, prepared statements, strict sessions, CSRF validation, login throttling, optional OTP, security headers, output escaping, safe logging, and first-admin setup protection.

The remaining work is mostly operational: serve over HTTPS, protect the web root, keep secrets out of Git, use a dedicated database user, keep PHPMailer updated, and make the admin password policy stricter if this is used for a sensitive system.

## Code-Level Coverage

| Area | Where | Coverage |
| --- | --- | --- |
| Environment loading | `config.php` | Reads `.env`, requires critical settings, rejects weak `APP_KEY`, and hides detailed errors when debug is off. |
| Database access | `config.php`, app pages | Uses PDO, `utf8mb4`, exceptions, default associative fetches, and disabled emulated prepares. |
| Password storage | `setup.php`, `index.php`, `database/schema.sql` | Stores password hashes and verifies them with PHP password APIs. |
| Password rehashing | `index.php` | Rehashes stored admin passwords when PHP says the hash needs an upgrade. |
| Sessions | `config.php` | Uses strict mode, cookies only, `HttpOnly`, `SameSite=Strict`, secure cookies on HTTPS, custom save path, idle timeout, absolute timeout, and session ID regeneration after login. |
| Session binding | `config.php` | Stores a keyed user-agent fingerprint and rejects sessions when it changes. |
| CSRF | `config.php`, `index.php`, `setup.php`, `logout.php` | Creates random tokens, validates with `hash_equals()`, and rotates tokens after sensitive transitions. |
| Login throttling | `index.php`, `database/schema.sql` | Tracks failed login attempts by IP and applies a timed lockout. Submitted emails are stored as keyed hashes. |
| Timing noise | `index.php` | Adds a small random delay after failed credentials. |
| Optional OTP | `index.php`, `mail.php` | Generates random six-digit codes, stores only a password hash of the OTP, expires codes, limits attempts, and aborts login if email delivery fails. |
| Authorization guard | `config.php`, `dashboard.php` | `require_admin()` protects the dashboard, checks expiry, checks fingerprint, and rejects inactive admins. |
| Logout | `logout.php` | Allows logout only through POST with a valid CSRF token, then destroys session data and clears the cookie. |
| Output escaping | `config.php`, views | Dynamic HTML uses the `h()` helper, which wraps `htmlspecialchars()`. |
| Security headers | `config.php`, `.htaccess` | Sends CSP, frame denial, MIME sniffing protection, referrer policy, permissions policy, no-store cache headers, and HSTS when HTTPS is detected. |
| File exposure | `.htaccess` | Denies direct access to `storage`, `database`, `docs`, `vendor`, dotfiles, env files, SQL dumps, logs, backups, temporary files, and Markdown files. |
| Profile image path | `dashboard.php` | Allows profile photos only under `assets/images/`, rejects `..`, and falls back to the logo when the file is missing. |
| Application logging | `config.php` | Writes JSON log lines and redacts keys that look like passwords, secrets, tokens, OTPs, CSRF values, salts, or keys. |
| Audit logging | `config.php`, app pages | Records setup, login, OTP, logout, dashboard, session, and CSRF events without writing raw passwords or OTP values. |

## Threats Covered

| Threat | Status | Notes |
| --- | --- | --- |
| Password leak from database | Covered | Passwords are stored as hashes, not plain text. |
| SQL injection | Covered | Database calls use prepared statements. Dynamic dashboard table names are allowlisted. |
| CSRF on login, setup, OTP, logout | Covered | POST flows validate CSRF tokens. |
| Session fixation | Covered | Session ID regenerates after successful login. |
| Session theft impact | Partly covered | `HttpOnly`, `SameSite=Strict`, timeouts, secure cookies on HTTPS, and fingerprint checks reduce risk. HTTPS is still required. |
| Brute-force login attempts | Partly covered | IP lockout is present. Shared networks and distributed attacks still need server or WAF controls. |
| OTP database disclosure | Covered | OTP values are hashed and short-lived. |
| Direct browsing of sensitive folders | Covered on Apache | `.htaccess` blocks sensitive paths when overrides are enabled. Confirm this on the server. |
| Secret leakage through logs | Partly covered | App log context is scrubbed. Server, PHP, SMTP, and database logs still need separate care. |
| XSS from dynamic output | Partly covered | Dynamic output uses escaping and CSP. Keep this habit when adding new views. |

## Important Remaining Responsibilities

These are not just nice-to-have items. They are the difference between good code and a safe deployment.

1. Serve the app through HTTPS.
2. Keep `APP_DEBUG=false` outside local development.
3. Keep `.env` out of Git and backups that leave the server.
4. Use a dedicated database user with only the permissions the app needs.
5. Confirm Apache honors `.htaccess` and blocks `storage`, `database`, `docs`, and `vendor`.
6. Keep `storage/logs` and `storage/sessions` writable by PHP but not publicly reachable.
7. Use a private SMTP account and rotate the password if it is exposed.
8. Keep PHPMailer updated.
9. Back up the database securely and encrypt off-server backups.
10. Review audit logs for repeated failures, CSRF failures, and unusual IP addresses.

## Findings And Improvement Notes

No critical issue was found in the reviewed application files.

Worth improving before a high-risk release:

| Priority | Item | Why |
| --- | --- | --- |
| High | Require stronger admin passwords than the current six-character setup minimum. | Six characters is easy to brute force if an admin reuses a weak password. |
| Medium | Add account-level throttling alongside IP throttling. | IP lockouts help, but distributed attacks can rotate IPs. |
| Medium | Add a formal admin management screen. | Enabling OTP or resetting passwords currently requires SQL. |
| Medium | Add role checks if more dashboard features are added. | The current app has one admin class. Extra features may need permissions. |
| Low | Add automated tests for login, CSRF, lockout, OTP expiry, and setup lockout. | Manual checks are useful, but tests catch regressions faster. |

## Manual Security Test Plan

Run these checks after setup and before sharing the project.

1. Open `dashboard.php` without a session. It should redirect to `index.php`.
2. Submit login without a valid CSRF token. It should fail and record a CSRF audit event.
3. Submit a wrong password several times. It should lock the IP after `LOGIN_MAX_ATTEMPTS`.
4. Log in with the right password. The dashboard should load and a login audit event should appear.
5. Log out with the normal button. The session should be destroyed.
6. Try to open `logout.php` with GET. It should not log out directly.
7. Enable `twofa_enabled = 1` for an admin and confirm OTP is required.
8. Enter a wrong OTP until attempts run out. The pending login should be cleared.
9. Wait beyond `OTP_EXPIRY_SECONDS` and confirm the OTP is rejected.
10. Confirm direct access to `storage`, `database`, `docs`, and `vendor` is forbidden by the web server.

## Secret Review Commands

Use this before committing or packaging the project:

```powershell
git status --short
rg -n --hidden --glob "!vendor/**" --glob "!storage/**" "(APP_KEY|DB_PASS|MAIL_PASSWORD|password|secret|token|api[_-]?key)" .
```

Expected result: the search may find safe templates and documentation, but it must not reveal real `.env` secrets, SMTP passwords, database passwords, private tokens, or real user data.

## Syntax Verification

```powershell
php -l config.php
php -l index.php
php -l dashboard.php
php -l logout.php
php -l setup.php
php -l mail.php
```

## Deployment Security Checklist

| Check | Done |
| --- | --- |
| HTTPS is enabled and tested. |  |
| `.env` exists only on the server and is not committed. |  |
| `APP_KEY` is random and at least 32 characters. |  |
| `APP_DEBUG=false`. |  |
| Database user is dedicated to this app. |  |
| `storage` is writable but not public. |  |
| `.htaccess` protection is active, or equivalent server rules exist. |  |
| SMTP credentials are private. |  |
| First admin was created and setup is now closed. |  |
| 2FA was tested for admins that need it. |  |
| Audit logs are being written. |  |
| Backups are encrypted and access controlled. |  |

## Incident Notes

If you suspect a secret leaked:

1. Rotate the leaked secret immediately.
2. Replace `APP_KEY` only with care, because it affects keyed hashes and session fingerprints.
3. Rotate database and SMTP passwords from their providers.
4. Clear active sessions by removing runtime session files.
5. Review `admin_logs` and server logs for the exposure window.
6. Re-check the repository for committed secrets before pushing again.
