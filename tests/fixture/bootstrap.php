<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
define('CRAFT_BASE_PATH', __DIR__);
define('CRAFT_VENDOR_PATH', __DIR__ . '/vendor');
require CRAFT_VENDOR_PATH . '/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
