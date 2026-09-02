<?php
declare(strict_types=1);

namespace Bench;

use Kotchasan\Controller as FrameworkController;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;

/**
 * Benchmark endpoints implemented with the framework's own Request/Response.
 */
class Controller extends FrameworkController
{
    public function index(Request $request)
    {
        $params = $request->getQueryParams();
        $module = $params['module'] ?? '';

        switch ($module) {
            case 'plaintext':
                return (new Response())->text('Hello, World!');

            case 'json':
                return $this->jsonResponse(['message' => 'Hello, World!']);

            case 'db':
                return $this->jsonResponse(bench_world_row(bench_pdo()));

            case 'queries':
                $n = bench_query_count((string) ($params['n'] ?? '20'));
                $pdo = bench_pdo();
                $rows = [];
                for ($i = 0; $i < $n; $i++) {
                    $rows[] = bench_world_row($pdo);
                }
                return $this->jsonResponse($rows);

            case 'fortunes':
                $fortunes = fetch_fortunes(bench_pdo());
                $fortunes[] = ['id' => 0, 'message' => 'Additional fortune added at request time.'];
                return (new Response())->html(render_fortunes_html($fortunes));

            case 'info':
                return $this->jsonResponse(bench_info_array());
        }

        return (new Response())->text('Not Found', 404);
    }

    private function jsonResponse($data): Response
    {
        return (new Response())
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withContent(json_encode($data));
    }
}
