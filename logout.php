<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

auth_start_session();
auth_logout();
redirect('/login');