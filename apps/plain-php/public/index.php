<?php
/**
 * plain-php — baseline app with no framework overhead.
 */
declare(strict_types=1);
require __DIR__.'/../bench_shared.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

switch ($path) {
    case '/plaintext':
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Hello, World!';
        return;

    case '/json':
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['message' => 'Hello, World!']);
        return;

    case '/db':
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(bench_world_row(bench_pdo()));
        return;

    case '/queries':
        header('Content-Type: application/json; charset=utf-8');
        $n = bench_query_count((string) ($_GET['n'] ?? '20'));
        $pdo = bench_pdo();
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = bench_world_row($pdo);
        }
        echo json_encode($rows);
        return;

    case '/fortunes':
        $fortunes = fetch_fortunes(bench_pdo());
        $fortunes[] = ['id' => 0, 'message' => 'Additional fortune added at request time.'];
        header('Content-Type: text/html; charset=utf-8');
        echo render_fortunes_html($fortunes);
        return;

    case '/info':
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(bench_info_array());
        return;

    default:
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not Found';
}
