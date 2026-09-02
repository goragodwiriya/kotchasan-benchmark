<?php
/**
 * Kotchasan benchmark app — bootstrap constants (same layout as GCMS).
 */
declare(strict_types=1);

define('ROOT_PATH', str_replace('\\', '/', dirname(__FILE__)).'/');
define('DATA_FOLDER', 'datas/');

/* Production-like settings, errors to system log only (no file writes) */
define('DEBUG', 0);
define('LOG_DESTINATION', 'LOG_SYSTEM');
define('DB_LOG', false);
define('DB_CACHE', false);
define('CACHE_DRIVER', 'memory');

/* Load the pinned Kotchasan framework copy */
require ROOT_PATH.'Kotchasan/load.php';
