<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

$wasLoggedIn = !empty($_SESSION['admin_id']);
$adminId = $wasLoggedIn ? (int) $_SESSION['admin_id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf'] ?? null;

    if (csrf_validate(is_string($postedToken) ? $postedToken : null)) {
        if ($adminId !== null) {
            admin_log($pdo, $adminId, 'logout');
        }

        destroy_current_session();
        redirect_to('index.php');
    }

    if ($adminId !== null) {
        admin_log($pdo, $adminId, 'csrf_failure', ['phase' => 'logout']);
    }

    redirect_to($wasLoggedIn ? 'dashboard.php' : 'index.php');
}

redirect_to($wasLoggedIn ? 'dashboard.php' : 'index.php');
