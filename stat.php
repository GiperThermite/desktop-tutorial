<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/stats.php';
stats_log_visit($_SERVER['REQUEST_URI'] ?? '/stat.php');
header('Content-Type: text/plain; charset=utf-8');
echo "OK";