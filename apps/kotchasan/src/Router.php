<?php
declare(strict_types=1);

namespace Bench;

/**
 * Minimal module router: /plaintext, /json, /db, /queries, /fortunes, /info
 * are all resolved to Bench\Controller::index with the "module" query param,
 * exactly like a standard Kotchasan module-based application.
 */
class Router extends \Kotchasan\Router
{
    protected $rules = [
        '/^([a-z]+)$/' => ['module'],
    ];
}
