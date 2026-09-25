<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
if (!db_ready()) {
    redirect('install.php');
}
$user = current_user();
if ($user) {
    redirect($user['role'] === 'admin' ? 'admin/index.php' : 'dashboard.php');
}
redirect('login.php');
