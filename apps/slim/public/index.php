<?php
/**
 * Slim 4 benchmark app.
 */
declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../bench_shared.php';

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;

$app = new App(new ResponseFactory());

function bench_json_response(Response $response, $data): Response
{
    $response->getBody()->write(json_encode($data));
    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
}

$app->get('/plaintext', function (Request $request, Response $response) {
    $response->getBody()->write('Hello, World!');
    return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
});

$app->get('/json', function (Request $request, Response $response) {
    return bench_json_response($response, ['message' => 'Hello, World!']);
});

$app->get('/db', function (Request $request, Response $response) {
    return bench_json_response($response, bench_world_row(bench_pdo()));
});

$app->get('/queries', function (Request $request, Response $response) {
    $n = bench_query_count((string) ($request->getQueryParams()['n'] ?? '20'));
    $pdo = bench_pdo();
    $rows = [];
    for ($i = 0; $i < $n; $i++) {
        $rows[] = bench_world_row($pdo);
    }
    return bench_json_response($response, $rows);
});

$app->get('/fortunes', function (Request $request, Response $response) {
    $fortunes = fetch_fortunes(bench_pdo());
    $fortunes[] = ['id' => 0, 'message' => 'Additional fortune added at request time.'];
    $response->getBody()->write(render_fortunes_html($fortunes));
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

$app->get('/info', function (Request $request, Response $response) {
    return bench_json_response($response, bench_info_array());
});

$app->run();
