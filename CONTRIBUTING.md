# Contributing

Thanks for helping improve SecureAuth Admin. The project is intentionally small, so contributions should keep the authentication flow easy to review and safe to deploy.

## Good Issues To Work On

- Stronger first-admin password rules.
- Account-level throttling alongside IP throttling.
- Admin management UI for enabling OTP without SQL.
- Automated tests for login, CSRF, lockout, OTP expiry, and setup lockout.
- Documentation improvements for common hosting panels.
- Accessibility improvements for the login and setup screens.

## Before You Open A Pull Request

1. Keep the change focused.
2. Do not commit `.env`, logs, sessions, backups, or real database dumps.
3. Run PHP syntax checks:

   ```powershell
   php -l config.php
   php -l index.php
   php -l dashboard.php
   php -l logout.php
   php -l setup.php
   php -l mail.php
   ```

4. Update documentation when behavior changes.
5. Mention any security impact in the pull request.

## Security Reports

Please do not open a public issue for a sensitive vulnerability. Use the guidance in [SECURITY.md](SECURITY.md).
