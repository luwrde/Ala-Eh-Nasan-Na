<?php
declare(strict_types=1);
// Admin pages share project authentication and data, with the supplied portal layout.
define('ADMIN_PORTAL', true);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/admin-layout.php';
