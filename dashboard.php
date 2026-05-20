<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

$admin = require_admin($pdo);

function dashboard_count(PDO $pdo, string $table, string $where = '', array $params = []): int
{
    $allowedTables = ['admins', 'login_attempts', 'admin_logs'];
    if (!in_array($table, $allowedTables, true)) {
        return 0;
    }

    try {
        $sql = "SELECT COUNT(*) FROM `$table`";
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        safe_log('Dashboard count failed', ['table' => $table, 'message' => $e->getMessage()]);
        return 0;
    }
}

function dashboard_recent_logs(PDO $pdo): array
{
    try {
        $stmt = $pdo->prepare('SELECT admin_id, action, ip, created_at FROM admin_logs ORDER BY id DESC LIMIT 8');
        $stmt->execute();
        $logs = $stmt->fetchAll();
        return is_array($logs) ? $logs : [];
    } catch (Throwable $e) {
        safe_log('Dashboard log query failed', ['message' => $e->getMessage()]);
        return [];
    }
}

function dashboard_asset(string $path, string $fallback): string
{
    $path = trim(str_replace('\\', '/', $path));

    if ($path === '' || strpos($path, 'assets/images/') !== 0 || strpos($path, '..') !== false) {
        return $fallback;
    }

    if (!is_file(__DIR__ . '/' . $path)) {
        return $fallback;
    }

    return $path;
}

$adminName = trim((string) ($admin['name'] ?? '')) ?: 'Admin';
$profilePhoto = dashboard_asset((string) ($admin['profile_photo'] ?? ''), 'assets/images/logo_main.png');
$recentLogs = dashboard_recent_logs($pdo);

$stats = [
    [
        'label' => 'Admins',
        'value' => dashboard_count($pdo, 'admins'),
        'abbr' => 'AD',
        'tone' => 'sky',
    ],
    [
        'label' => '2FA Enabled',
        'value' => dashboard_count($pdo, 'admins', 'twofa_enabled = ?', [1]),
        'abbr' => '2F',
        'tone' => 'green',
    ],
    [
        'label' => 'Locked IPs',
        'value' => dashboard_count($pdo, 'login_attempts', 'attempts >= ?', [(int) MAX_LOGIN_ATTEMPTS]),
        'abbr' => 'LK',
        'tone' => 'amber',
    ],
    [
        'label' => 'Audit Logs',
        'value' => dashboard_count($pdo, 'admin_logs'),
        'abbr' => 'LG',
        'tone' => 'rose',
    ],
];

admin_log($pdo, (int) $admin['id'], 'dashboard_access');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h(app_config('app_name')) ?> | Dashboard</title>
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>
    <div class="admin-wrapper">
        <aside class="admin-sidebar">
            <div class="sidebar-brand">
                <a class="logo" href="dashboard.php"><span>SecureAuth</span> Admin</a>
            </div>

            <nav class="sidebar-nav" aria-label="Main navigation">
                <div class="nav-group">
                    <div class="nav-group-title">Main</div>
                    <a class="nav-item active" href="dashboard.php">
                        <span class="nav-mark">D</span>
                        <span>Dashboard</span>
                    </a>
                </div>

                <div class="nav-group">
                    <div class="nav-group-title">Security</div>
                    <a class="nav-item" href="dashboard.php#activity">
                        <span class="nav-mark">A</span>
                        <span>Activity</span>
                    </a>
                    <a class="nav-item" href="dashboard.php#session">
                        <span class="nav-mark">S</span>
                        <span>Session</span>
                    </a>
                </div>
            </nav>
        </aside>

        <main class="admin-main">
            <header class="admin-header">
                <div class="header-left">
                    <button class="sidebar-toggle" type="button" data-toggle="sidebar" aria-label="Open sidebar">
                        <span></span>
                        <span></span>
                        <span></span>
                    </button>
                    <div>
                        <div class="header-title">Admin Dashboard</div>
                        <p class="header-subtitle">Signed in as <?= h($admin['email']) ?></p>
                    </div>
                </div>

                <div class="header-right">
                    <div class="profile-chip">
                        <img src="<?= h($profilePhoto) ?>" alt="">
                        <span><?= h($adminName) ?></span>
                    </div>
                    <form method="post" action="logout.php">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <button class="btn btn-secondary" type="submit">Logout</button>
                    </form>
                </div>
            </header>

            <section class="admin-content">
                <div class="page-header dashboard-hero">
                    <div>
                        <p class="eyebrow">Secure login</p>
                        <h1>Welcome back, <?= h($adminName) ?></h1>
                        <p>Monitor administrator access, authentication health, and recent audit events.</p>
                    </div>
                    <div class="hero-lock" aria-hidden="true">SA</div>
                </div>

                <div class="dashboard-cards">
                    <?php foreach ($stats as $stat): ?>
                        <div class="dashboard-card stat-<?= h($stat['tone']) ?>">
                            <div class="dashboard-card-title"><?= h($stat['label']) ?></div>
                            <div class="dashboard-card-value"><?= number_format((int) $stat['value']) ?></div>
                            <div class="dashboard-card-icon" aria-hidden="true"><?= h($stat['abbr']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="dashboard-grid">
                    <section class="panel" id="activity">
                        <div class="panel-header">Recent Activity</div>
                        <div class="panel-body">
                            <?php if ($recentLogs): ?>
                                <div class="activity-list">
                                    <?php foreach ($recentLogs as $log): ?>
                                        <div class="activity-item">
                                            <span class="activity-dot"></span>
                                            <div>
                                                <strong><?= h($log['action']) ?></strong>
                                                <p><?= h($log['created_at']) ?> from <?= h($log['ip'] ?? 'unknown') ?></p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state">No activity recorded yet.</div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="panel" id="session">
                        <div class="panel-header">Session</div>
                        <div class="panel-body">
                            <div class="session-list">
                                <div>
                                    <span>Status</span>
                                    <strong>Active</strong>
                                </div>
                                <div>
                                    <span>2FA</span>
                                    <strong><?= ((int) ($admin['twofa_enabled'] ?? 0) === 1) ? 'Enabled' : 'Disabled' ?></strong>
                                </div>
                                <div>
                                    <span>Current IP</span>
                                    <strong><?= h(get_client_ip()) ?></strong>
                                </div>
                                <div>
                                    <span>Last Login</span>
                                    <strong><?= h($admin['last_login_at'] ?: 'Not recorded') ?></strong>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </section>
        </main>
    </div>

    <script src="assets/js/admin.js"></script>
</body>
</html>
