<?php
/**
 * Kotchasan benchmark app — front controller.
 */
declare(strict_types=1);

include __DIR__.'/load.php';

require __DIR__.'/bench_shared.php';
require __DIR__.'/src/Config.php';
require __DIR__.'/src/Router.php';
require __DIR__.'/src/Controller.php';

$app = Kotchasan::createWebApplication('Bench\Config');
$app->defaultController = 'Bench\Controller';
$app->defaultRouter = 'Bench\Router';
$app->run();
