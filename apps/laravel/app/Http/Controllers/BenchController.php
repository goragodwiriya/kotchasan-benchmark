<?php

declare(strict_types=1);

namespace App\Http\Controllers;

require_once dirname(__DIR__, 3).'/bench_shared.php';

class BenchController extends Controller
{
    public function plaintext()
    {
        return response('Hello, World!', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function json()
    {
        return $this->jsonResponse(['message' => 'Hello, World!']);
    }

    public function db()
    {
        return $this->jsonResponse(bench_world_row(bench_pdo()));
    }

    public function queries()
    {
        $n = bench_query_count((string) (request()->query('n', '20')));
        $pdo = bench_pdo();
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = bench_world_row($pdo);
        }
        return $this->jsonResponse($rows);
    }

    public function fortunes()
    {
        $fortunes = fetch_fortunes(bench_pdo());
        $fortunes[] = ['id' => 0, 'message' => 'Additional fortune added at request time.'];
        return response(render_fortunes_html($fortunes), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function info()
    {
        return $this->jsonResponse(bench_info_array());
    }

    private function jsonResponse($data)
    {
        return response(json_encode($data), 200, ['Content-Type' => 'application/json; charset=utf-8']);
    }
}
