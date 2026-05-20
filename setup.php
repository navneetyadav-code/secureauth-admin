<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

function setup_is_local_request(): bool
{
    $ip = get_client_ip();
    return in_array($ip, ['127.0.0.1', '::1'], true);
}

function setup_token_is_valid(): bool
{
    $configuredToken = env_value('SETUP_TOKEN', '') ?? '';
    if ($configuredToken === '') {
        return setup_is_local_request();
    }

    $submittedToken = $_POST['setup_token'] ?? $_GET['token'] ?? '';
    return is_string($submittedToken) && hash_equals($configuredToken, $submittedToken);
}

function setup_table_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
         ORDER BY ORDINAL_POSITION'
    );
    $stmt->execute([$table]);

    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!is_array($columns)) {
        return [];
    }

    return array_map('strval', $columns);
}

function setup_admin_count(PDO $pdo): int
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM admins');
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        safe_log('Setup could not count admins', ['message' => $e->getMessage()]);
        return -1;
    }
}

function setup_password_errors(string $password): array
{
    $errors = [];

    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }

    return $errors;
}

function setup_insert_first_admin(PDO $pdo, array $columns, string $name, string $email, string $password): int
{
    $hasPasswordHash = in_array('password_hash', $columns, true);
    $hasLegacyPassword = in_array('password', $columns, true);

    if (!$hasPasswordHash && !$hasLegacyPassword) {
        throw new RuntimeException('No supported password column exists.');
    }

    $insert = [];
    $values = [];
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    if (in_array('name', $columns, true)) {
        $insert['name'] = $name;
    }

    $insert['email'] = $email;

    if ($hasPasswordHash) {
        $insert['password_hash'] = $passwordHash;
    }

    if ($hasLegacyPassword) {
        $insert['password'] = $passwordHash;
    }

    if (in_array('twofa_enabled', $columns, true)) {
        $insert['twofa_enabled'] = 0;
    }

    if (in_array('is_active', $columns, true)) {
        $insert['is_active'] = 1;
    }

    if (in_array('profile_photo', $columns, true)) {
        $insert['profile_photo'] = '';
    }

    if (in_array('created_at', $columns, true)) {
        $insert['created_at'] = now_sql();
    }

    if (in_array('updated_at', $columns, true)) {
        $insert['updated_at'] = now_sql();
    }

    foreach ($insert as $value) {
        $values[] = $value;
    }

    $columnSql = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', array_keys($insert)));
    $placeholders = implode(', ', array_fill(0, count($insert), '?'));

    $stmt = $pdo->prepare('INSERT INTO admins (' . $columnSql . ') VALUES (' . $placeholders . ')');
    $stmt->execute($values);

    return (int) $pdo->lastInsertId();
}

$appName = (string) app_config('app_name');
$columns = setup_table_columns($pdo, 'admins');
$adminCount = $columns === [] ? -1 : setup_admin_count($pdo);
$tokenAllowed = setup_token_is_valid();
$setupAvailable = $tokenAllowed && $columns !== [] && $adminCount === 0;
$success = false;
$error = '';
$messages = [];

csrf_token();

if (!$tokenAllowed) {
    $error = 'Setup is locked. Configure SETUP_TOKEN and open setup.php?token=your-token.';
} elseif ($columns === []) {
    $error = 'Database schema is not installed. Import database/schema.sql first.';
} elseif ($adminCount > 0) {
    $error = 'Setup is already complete. Admin credentials are configured.';
} elseif ($adminCount < 0) {
    $error = 'Setup cannot verify the admin table.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $setupAvailable) {
    $postedToken = $_POST['csrf'] ?? null;

    if (!csrf_validate(is_string($postedToken) ? $postedToken : null)) {
        csrf_rotate();
        $error = 'Your setup session expired. Refresh and try again.';
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['password_confirm'] ?? '');

        if ($name === '') {
            $name = 'Admin';
        }

        if (strlen($name) > 100) {
            $messages[] = 'Name must be 100 characters or fewer.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $messages[] = 'Enter a valid admin email address.';
        }

        if (!hash_equals($password, $confirmPassword)) {
            $messages[] = 'Password confirmation does not match.';
        }

        $messages = array_merge($messages, setup_password_errors($password));

        if ($messages === []) {
            try {
                $pdo->beginTransaction();

                $lockedCount = setup_admin_count($pdo);
                if ($lockedCount !== 0) {
                    throw new RuntimeException('Admin setup has already been completed.');
                }

                $adminId = setup_insert_first_admin($pdo, $columns, $name, $email, $password);
                $pdo->commit();

                csrf_rotate();
                admin_log($pdo, $adminId, 'setup_admin_created');
                safe_log('First admin account created', ['admin_id' => $adminId, 'email_hash' => keyed_hash($email)]);
                $success = true;
                $setupAvailable = false;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                safe_log('First admin setup failed', ['message' => $e->getMessage()]);
                $error = 'Could not create the admin account. Check server logs.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>First Admin Setup | <?= h($appName) ?></title>
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body class="premium-login-page">
    <main class="premium-login-shell setup-shell" aria-labelledby="setup-title">
        <section class="premium-login-card">
            <div class="premium-card-shine" aria-hidden="true"></div>

            <header class="premium-login-brand">
                <div class="premium-logo-wrap">
                    <img src="assets/images/logo_main.png" alt="<?= h($appName) ?> logo">
                </div>

                <h1 id="setup-title">First <span>Setup</span></h1>
                <p>Secureauth Security Gateway</p>
            </header>

            <?php if ($success): ?>
                <div class="premium-alert premium-alert-success" role="status">
                    <span class="premium-alert-mark" aria-hidden="true">OK</span>
                    <div>Admin account created. Setup is now disabled.</div>
                </div>
                <a class="premium-link-button" href="index.php">Go to login</a>
            <?php else: ?>
                <?php if ($error !== ''): ?>
                    <div class="premium-alert premium-alert-warning" role="alert">
                        <span class="premium-alert-mark" aria-hidden="true">!</span>
                        <div><?= h($error) ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($messages !== []): ?>
                    <div class="premium-alert premium-alert-error" role="alert">
                        <span class="premium-alert-mark" aria-hidden="true">!</span>
                        <div>
                            <?php foreach ($messages as $message): ?>
                                <p><?= h($message) ?></p>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($setupAvailable): ?>
                    <form method="post" class="premium-auth-form" autocomplete="off" novalidate>
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <?php if ((env_value('SETUP_TOKEN', '') ?? '') !== ''): ?>
                            <input type="hidden" name="setup_token" value="<?= h((string) ($_GET['token'] ?? $_POST['setup_token'] ?? '')) ?>">
                        <?php endif; ?>

                        <div class="premium-field">
                            <label for="name">Admin Name</label>
                            <input id="name" name="name" type="text" maxlength="100" autocomplete="name" placeholder="Admin" value="<?= h((string) ($_POST['name'] ?? '')) ?>">
                        </div>

                        <div class="premium-field">
                            <label for="email">Admin Email</label>
                            <input id="email" name="email" type="email" maxlength="255" autocomplete="username" required placeholder="admin@example.com" value="<?= h((string) ($_POST['email'] ?? '')) ?>">
                        </div>

                        <div class="premium-field">
                            <label for="password">Password</label>
                            <input id="password" name="password" type="password" autocomplete="new-password" required placeholder="Minimum 6 characters">
                        </div>

                        <div class="premium-field">
                            <label for="password_confirm">Confirm Password</label>
                            <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required placeholder="Repeat password">
                        </div>

                        <button class="premium-submit" type="submit">Create Admin</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <p class="premium-login-footnote">
            <svg class="premium-footer-lock" aria-hidden="true" viewBox="0 0 24 24" focusable="false">
                <rect x="5" y="11" width="14" height="10" rx="2"></rect>
                <path d="M8 11V7a4 4 0 0 1 8 0v4"></path>
            </svg>
            First-Time Credential Setup
        </p>
    </main>
</body>
</html>
