<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/config.php';

if (!empty($_SESSION['admin_id'])) {
    redirect_to('dashboard.php');
}

function login_attempt_record(PDO $pdo, string $ip): ?array
{
    $stmt = $pdo->prepare('SELECT ip, attempts, last_attempt FROM login_attempts WHERE ip = ? LIMIT 1');
    $stmt->execute([$ip]);
    $record = $stmt->fetch();

    return is_array($record) ? $record : null;
}

function login_lockout_status(PDO $pdo, string $ip): array
{
    $record = login_attempt_record($pdo, $ip);
    if ($record === null || (int) $record['attempts'] < MAX_LOGIN_ATTEMPTS) {
        return ['locked' => false, 'seconds' => 0];
    }

    $lastAttempt = strtotime((string) $record['last_attempt']);
    if ($lastAttempt === false) {
        $lastAttempt = 0;
    }

    $unlockAt = $lastAttempt + LOCKOUT_TIME;
    if (time() < $unlockAt) {
        return ['locked' => true, 'seconds' => $unlockAt - time()];
    }

    $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE ip = ?');
    $stmt->execute([$ip]);

    return ['locked' => false, 'seconds' => 0];
}

function record_failed_login(PDO $pdo, string $ip, string $email): void
{
    $storedEmail = filter_var($email, FILTER_VALIDATE_EMAIL) ? keyed_hash($email, 64) : null;
    $now = now_sql();

    $stmt = $pdo->prepare(
        'INSERT INTO login_attempts (ip, email, attempts, last_attempt, created_at)
         VALUES (?, ?, 1, ?, ?)
         ON DUPLICATE KEY UPDATE attempts = attempts + 1, email = VALUES(email), last_attempt = VALUES(last_attempt)'
    );
    $stmt->execute([$ip, $storedEmail, $now, $now]);
}

function clear_login_attempts(PDO $pdo, string $ip): void
{
    $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE ip = ?');
    $stmt->execute([$ip]);
}

function fetch_admin_by_email(PDO $pdo, string $email): ?array
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM admins WHERE email = ? LIMIT 1');
    $stmt->execute([strtolower($email)]);
    $admin = $stmt->fetch();

    return is_array($admin) ? $admin : null;
}

function dummy_password_hash(): string
{
    static $hash = null;

    if ($hash === null) {
        $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    }

    return $hash;
}

function admin_password_column_and_hash(array $admin): ?array
{
    foreach (['password_hash', 'password'] as $column) {
        if (array_key_exists($column, $admin) && is_string($admin[$column]) && trim($admin[$column]) !== '') {
            return [$column, $admin[$column]];
        }
    }

    return null;
}

function rehash_admin_password_if_needed(PDO $pdo, array $admin, string $password, string $column, string $hash): void
{
    if (!in_array($column, ['password_hash', 'password'], true) || !password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        return;
    }

    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('UPDATE admins SET `' . $column . '` = ? WHERE id = ?');
    $stmt->execute([$newHash, (int) $admin['id']]);
}

function clear_admin_otp(PDO $pdo, int $adminId): void
{
    $stmt = $pdo->prepare('UPDATE admins SET otp = NULL, otp_expiry = NULL WHERE id = ?');
    $stmt->execute([$adminId]);
}

function clear_pending_2fa_session(): void
{
    unset(
        $_SESSION['pending_2fa_admin_id'],
        $_SESSION['pending_2fa_started_at'],
        $_SESSION['pending_2fa_fingerprint'],
        $_SESSION['otp_attempts']
    );
}

function otp_email_body(string $otp): string
{
    $minutes = (int) ceil(OTP_EXPIRY_SECONDS / 60);
    $appName = h(app_config('app_name'));

    return '<p>Use this verification code to complete your ' . $appName . ' admin sign in.</p>'
        . '<p><strong>' . h($otp) . '</strong></p>'
        . '<p>This code expires in ' . $minutes . ' minutes. If you did not request it, ignore this email.</p>';
}

function start_two_factor(PDO $pdo, array $admin): bool
{
    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otpHash = password_hash($otp, PASSWORD_DEFAULT);
    $expiresAt = time() + OTP_EXPIRY_SECONDS;

    $stmt = $pdo->prepare('UPDATE admins SET otp = ?, otp_expiry = ? WHERE id = ?');
    $stmt->execute([$otpHash, $expiresAt, (int) $admin['id']]);

    require_once __DIR__ . '/mail.php';

    $mailSent = send_admin_mail((string) $admin['email'], 'Admin access verification', otp_email_body($otp));

    if (!$mailSent) {
        clear_admin_otp($pdo, (int) $admin['id']);
        admin_log($pdo, (int) $admin['id'], 'login_failed', ['reason' => 'otp_email_failed']);
        return false;
    }

    $_SESSION['pending_2fa_admin_id'] = (int) $admin['id'];
    $_SESSION['pending_2fa_started_at'] = time();
    $_SESSION['pending_2fa_fingerprint'] = session_fingerprint();
    $_SESSION['otp_attempts'] = 0;
    csrf_rotate();

    admin_log($pdo, (int) $admin['id'], 'otp_requested');
    return true;
}

function abort_pending_2fa(PDO $pdo, int $adminId, string $reason, string $message): never
{
    clear_admin_otp($pdo, $adminId);
    clear_pending_2fa_session();
    admin_log($pdo, $adminId, 'otp_failure', ['reason' => $reason]);
    flash_message('login_alert', $message);
    csrf_rotate();
    redirect_to('index.php');
}

$error = '';
$infoMessage = flash_message('login_alert') ?? '';
$isTwoFactorMode = !empty($_SESSION['pending_2fa_admin_id']);
$clientIp = get_client_ip();
csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf'] ?? null;

    if (!csrf_validate(is_string($postedToken) ? $postedToken : null)) {
        admin_log($pdo, null, 'csrf_failure', ['phase' => $isTwoFactorMode ? 'otp' : 'login']);
        csrf_rotate();
        $error = 'Your session expired. Please refresh and try again.';
    } elseif ($isTwoFactorMode) {
        $pendingAdminId = (int) $_SESSION['pending_2fa_admin_id'];

        if (isset($_POST['cancel'])) {
            clear_admin_otp($pdo, $pendingAdminId);
            clear_pending_2fa_session();
            admin_log($pdo, $pendingAdminId, 'otp_canceled');
            csrf_rotate();
            redirect_to('index.php');
        }

        if (
            empty($_SESSION['pending_2fa_fingerprint'])
            || !hash_equals((string) $_SESSION['pending_2fa_fingerprint'], session_fingerprint())
        ) {
            destroy_current_session();
            start_secure_session();
            flash_message('login_alert', 'Verification expired. Please sign in again.');
            admin_log($pdo, $pendingAdminId, 'otp_failure', ['reason' => 'session_mismatch']);
            redirect_to('index.php');
        }

        if ((time() - (int) ($_SESSION['pending_2fa_started_at'] ?? 0)) > OTP_EXPIRY_SECONDS) {
            abort_pending_2fa($pdo, $pendingAdminId, 'session_expired', 'Verification expired. Please sign in again.');
        }

        $_SESSION['otp_attempts'] = (int) ($_SESSION['otp_attempts'] ?? 0);
        if ($_SESSION['otp_attempts'] >= MAX_OTP_ATTEMPTS) {
            abort_pending_2fa($pdo, $pendingAdminId, 'too_many_attempts', 'Too many verification attempts. Please sign in again.');
        }

        $code = trim((string) ($_POST['otp'] ?? ''));
        $admin = fetch_admin_by_id($pdo, $pendingAdminId);
        $otpHash = is_array($admin) ? (string) ($admin['otp'] ?? '') : '';
        $otpExpiry = is_array($admin) ? (int) ($admin['otp_expiry'] ?? 0) : 0;

        $validFormat = preg_match('/^\d{6}$/', $code) === 1;
        $validOtp = $validFormat && $otpHash !== '' && password_verify($code, $otpHash);
        $notExpired = $otpExpiry > time();

        if ($admin !== null && $validOtp && $notExpired) {
            clear_admin_otp($pdo, (int) $admin['id']);
            clear_pending_2fa_session();
            complete_admin_login($pdo, $admin, 'login_success_2fa');
            clear_login_attempts($pdo, $clientIp);
            redirect_to('dashboard.php');
        }

        $_SESSION['otp_attempts']++;
        admin_log($pdo, $pendingAdminId, 'otp_failure', ['reason' => $notExpired ? 'invalid_code' : 'expired_code']);

        if ($_SESSION['otp_attempts'] >= MAX_OTP_ATTEMPTS || !$notExpired) {
            abort_pending_2fa($pdo, $pendingAdminId, $notExpired ? 'too_many_attempts' : 'expired_code', 'Verification failed. Please sign in again.');
        }

        $remaining = MAX_OTP_ATTEMPTS - (int) $_SESSION['otp_attempts'];
        $error = 'Invalid or expired verification code. Attempts remaining: ' . $remaining . '.';
    } else {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $lockout = login_lockout_status($pdo, $clientIp);

        if ($lockout['locked']) {
            admin_log($pdo, null, 'login_failed', [
                'reason' => 'ip_lockout',
                'email_hash' => $email !== '' ? keyed_hash($email) : 'empty',
            ]);
            $minutes = max(1, (int) ceil(((int) $lockout['seconds']) / 60));
            $error = 'Too many login attempts. Please try again in about ' . $minutes . ' minute(s).';
        } else {
            $admin = fetch_admin_by_email($pdo, $email);
            $adminForLog = $admin;
            if ($admin !== null && !admin_is_active($admin)) {
                $admin = null;
            }

            $passwordData = $admin !== null ? admin_password_column_and_hash($admin) : null;
            $storedHash = $passwordData[1] ?? dummy_password_hash();
            $passwordOk = password_verify($password, $storedHash);

            if ($admin !== null && $passwordData !== null && $passwordOk) {
                rehash_admin_password_if_needed($pdo, $admin, $password, $passwordData[0], $passwordData[1]);

                if ((int) ($admin['twofa_enabled'] ?? 0) === 1) {
                    if (start_two_factor($pdo, $admin)) {
                        redirect_to('index.php');
                    }

                    $error = 'Unable to send the verification email. Login was aborted.';
                } else {
                    clear_admin_otp($pdo, (int) $admin['id']);
                    complete_admin_login($pdo, $admin, 'login_success');
                    clear_login_attempts($pdo, $clientIp);
                    redirect_to('dashboard.php');
                }
            } else {
                record_failed_login($pdo, $clientIp, $email);
                admin_log($pdo, $adminForLog !== null ? (int) $adminForLog['id'] : null, 'login_failed', [
                    'reason' => 'invalid_credentials',
                    'email_hash' => $email !== '' ? keyed_hash($email) : 'empty',
                ]);
                usleep(random_int(200000, 500000));
                $error = 'Invalid email or password.';
            }
        }
    }
}

$appName = (string) app_config('app_name');
$pageTitle = $isTwoFactorMode ? 'Verify Access' : 'System Access';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Portal | <?= h($appName) ?></title>
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body class="premium-login-page">
    <main class="premium-login-shell" aria-labelledby="login-title">
        <section class="premium-login-card">
            <div class="premium-card-shine" aria-hidden="true"></div>

            <header class="premium-login-brand">
                <div class="premium-logo-wrap">
                    <img src="assets/images/logo_main.png" alt="<?= h($appName) ?> logo">
                </div>

                <h1 id="login-title"><?= $isTwoFactorMode ? 'Verify <span>Access</span>' : 'System <span>Access</span>' ?></h1>
                <p>Secureauth Security Gateway</p>
            </header>

            <?php if ($error !== ''): ?>
                <div class="premium-alert premium-alert-error" role="alert">
                    <span class="premium-alert-mark" aria-hidden="true">!</span>
                    <div><?= h($error) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($infoMessage !== ''): ?>
                <div class="premium-alert premium-alert-warning" role="status">
                    <span class="premium-alert-mark" aria-hidden="true">!</span>
                    <div><?= h($infoMessage) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($isTwoFactorMode): ?>
                <form method="post" class="premium-auth-form" autocomplete="off" novalidate>
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

                    <div class="premium-otp-note">
                        <span aria-hidden="true">OTP</span>
                        <p>Security code dispatched to your registered email.</p>
                    </div>

                    <div class="premium-field">
                        <label for="otp">Authorization Code</label>
                        <input
                            id="otp"
                            name="otp"
                            type="text"
                            inputmode="numeric"
                            pattern="[0-9]{6}"
                            maxlength="6"
                            autocomplete="one-time-code"
                            placeholder="------"
                            class="premium-otp-input"
                            required
                        >
                    </div>

                    <button class="premium-submit" type="submit">Verify Identity</button>
                    <button class="premium-cancel" type="submit" name="cancel" value="1" formnovalidate>Abort Process</button>
                </form>
            <?php else: ?>
                <form method="post" class="premium-auth-form" autocomplete="off" novalidate>
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input class="visually-hidden" type="text" name="fake_email" aria-hidden="true" autocomplete="off" tabindex="-1">
                    <input class="visually-hidden" type="password" name="fake_password" aria-hidden="true" autocomplete="off" tabindex="-1">

                    <div class="premium-field">
                        <label for="email">Admin Email</label>
                        <div class="premium-input-wrap">
                            <svg class="premium-input-icon" aria-hidden="true" viewBox="0 0 24 24" focusable="false">
                                <path d="M20 21a8 8 0 0 0-16 0"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                            <input
                                id="email"
                                name="email"
                                type="email"
                                autocomplete="username"
                                maxlength="255"
                                spellcheck="false"
                                placeholder="Enter your administrative email"
                                required
                            >
                        </div>
                    </div>

                    <div class="premium-field">
                        <label for="password">Secure Password</label>
                        <div class="premium-input-wrap">
                            <svg class="premium-input-icon" aria-hidden="true" viewBox="0 0 24 24" focusable="false">
                                <rect x="5" y="11" width="14" height="10" rx="2"></rect>
                                <path d="M8 11V7a4 4 0 0 1 8 0v4"></path>
                            </svg>
                            <input
                                id="password"
                                name="password"
                                type="password"
                                autocomplete="current-password"
                                placeholder="************"
                                required
                            >
                        </div>
                    </div>

                    <button class="premium-submit" type="submit">Authenticate</button>
                </form>
            <?php endif; ?>
        </section>

        <p class="premium-login-footnote">
            <svg class="premium-footer-lock" aria-hidden="true" viewBox="0 0 24 24" focusable="false">
                <rect x="5" y="11" width="14" height="10" rx="2"></rect>
                <path d="M8 11V7a4 4 0 0 1 8 0v4"></path>
            </svg>
            Encrypted Connection
        </p>
    </main>
</body>
</html>
