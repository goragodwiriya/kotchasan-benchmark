<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

require_once dirname(__DIR__, 2).'/bench_shared.php';

class BenchController
{
    #[Route('/plaintext', methods: ['GET'])]
    public function plaintext(): Response
    {
        return new Response('Hello, World!', 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    #[Route('/json', methods: ['GET'])]
    public function json(): Response
    {
        return $this->jsonResponse(['message' => 'Hello, World!']);
    }

    #[Route('/db', methods: ['GET'])]
    public function db(): Response
    {
        return $this->jsonResponse(bench_world_row(bench_pdo()));
    }

    #[Route('/queries', methods: ['GET'])]
    public function queries(): Response
    {
        $n = bench_query_count((string) ($_GET['n'] ?? '20'));
        $pdo = bench_pdo();
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = bench_world_row($pdo);
        }
        return $this->jsonResponse($rows);
    }

    #[Route('/fortunes', methods: ['GET'])]
    public function fortunes(): Response
    {
        $fortunes = fetch_fortunes(bench_pdo());
        $fortunes[] = ['id' => 0, 'message' => 'Additional fortune added at request time.'];
        return new Response(render_fortunes_html($fortunes), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    #[Route('/info', methods: ['GET'])]
    public function info(): Response
    {
        return $this->jsonResponse(bench_info_array());
    }

    private function jsonResponse($data): JsonResponse
    {
        return new JsonResponse(json_encode($data), 200, ['Content-Type' => 'application/json; charset=utf-8'], true);
    }
}
