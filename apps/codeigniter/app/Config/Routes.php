<?php

use CodeIgniter\Router\RouteCollection;

$routes = service('routes');

/** @var RouteCollection $routes */
$routes->setDefaultNamespace('App\Controllers');

$routes->get('/plaintext', 'Bench::plaintext');
$routes->get('/json', 'Bench::json');
$routes->get('/db', 'Bench::db');
$routes->get('/queries', 'Bench::queries');
$routes->get('/fortunes', 'Bench::fortunes');
$routes->get('/info', 'Bench::info');
