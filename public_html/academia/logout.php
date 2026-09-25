<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/site_admin.php';
site_admin_revoke();
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
