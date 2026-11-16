<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
if (!db_ready()) {
    redirect('install.php');
}
$user = current_user();
if ($user) {
    redirect(is_staff($user) ? staff_home_for_role($user) : 'dashboard.php');
}
redirect('login.php');
