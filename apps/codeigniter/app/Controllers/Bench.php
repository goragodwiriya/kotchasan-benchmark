<?php

declare(strict_types=1);

namespace App\Controllers;

require_once dirname(__DIR__, 2).'/bench_shared.php';

class Bench extends BaseController
{
    public function plaintext()
    {
        return $this->response
            ->setContentType('text/plain', 'utf-8')
            ->setBody('Hello, World!');
    }

    public function json()
    {
        return $this->response
            ->setContentType('application/json', 'utf-8')
            ->setBody(json_encode(['message' => 'Hello, World!']));
    }

    public function db()
    {
        return $this->response
            ->setContentType('application/json', 'utf-8')
            ->setBody(json_encode(bench_world_row(bench_pdo())));
    }

    public function queries()
    {
        $n = bench_query_count((string) ($this->request->getGet('n') ?? '20'));
        $pdo = bench_pdo();
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = bench_world_row($pdo);
        }
        return $this->response
            ->setContentType('application/json', 'utf-8')
            ->setBody(json_encode($rows));
    }

    public function fortunes()
    {
        $fortunes = fetch_fortunes(bench_pdo());
        $fortunes[] = ['id' => 0, 'message' => 'Additional fortune added at request time.'];
        return $this->response
            ->setContentType('text/html', 'utf-8')
            ->setBody(render_fortunes_html($fortunes));
    }

    public function info()
    {
        return $this->response
            ->setContentType('application/json', 'utf-8')
            ->setBody(json_encode(bench_info_array()));
    }
}
