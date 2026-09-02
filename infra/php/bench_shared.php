<?php
/**
 * Shared benchmark helpers — byte-identical in every app container.
 * Copied by the Dockerfile to /app/bench_shared.php for all frameworks.
 *
 * All DB access intentionally uses raw PDO with identical SQL and options so
 * that measured differences come from the framework request pipeline
 * (bootstrap, routing, middleware, response emitting), not from ORM choices.
 */
declare(strict_types=1);

function bench_pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                getenv('BENCH_DB_HOST') ?: 'db',
                getenv('BENCH_DB_PORT') ?: '3306',
                getenv('BENCH_DB_NAME') ?: 'benchmark'
            ),
            getenv('BENCH_DB_USER') ?: 'bench',
            getenv('BENCH_DB_PASS') ?: 'bench',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                // Persistent connections, as production apps use under load:
                // without this every request opens a new MySQL connection
                // (~2k/s) and connection churn, not the framework, dominates.
                PDO::ATTR_PERSISTENT => true,
            ]
        );
    }
    return $pdo;
}

/** One random world row (TechEmpower semantics). */
function bench_world_row(PDO $pdo): array
{
    static $stmt = null;
    $stmt ??= $pdo->prepare('SELECT id, randomNumber FROM world WHERE id = ?');
    $stmt->execute([random_int(1, 10000)]);
    $row = $stmt->fetch();
    return ['id' => (int) $row['id'], 'randomNumber' => (int) $row['randomNumber']];
}

/** Clamp ?n= like TechEmpower: default 1, range 1..500. */
function bench_query_count(string $raw): int
{
    $n = (int) $raw;
    return max(1, min(500, $n === 0 ? 1 : $n));
}

/**
 * Fortune rendering — identical pure-PHP template in every app so the HTML
 * output is byte-identical across frameworks (verifiable in `make verify`).
 */
function render_fortunes_html(array $fortunes): string
{
    usort($fortunes, function ($a, $b) {
        return strcmp($a['message'], $b['message']);
    });
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Fortunes</title></head>'
        .'<body><table><tr><th>id</th><th>message</th></tr>';
    foreach ($fortunes as $f) {
        $html .= '<tr><td>'.(int) $f['id'].'</td><td>'
            .htmlspecialchars((string) $f['message'], ENT_QUOTES, 'UTF-8')
            .'</td></tr>';
    }
    return $html.'</table></body></html>';
}

function fetch_fortunes(PDO $pdo): array
{
    static $stmt = null;
    $stmt ??= $pdo->query('SELECT id, message FROM fortune');
    return $stmt->fetchAll();
}

function bench_info_array(): array
{
    return [
        'php_version' => PHP_VERSION,
        // false = actual bytes used by the script, not allocator chunks
        // (true would round every app up to the same 2 MB)
        'peak_memory_bytes' => memory_get_peak_usage(false),
        'included_files' => count(get_included_files()),
    ];
}
