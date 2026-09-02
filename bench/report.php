<?php
/**
 * Report generator.
 *
 *   php bench/report.php results/raw/<tag>     -> <tag>/report.md + summary.csv
 *   php bench/report.php <dirA> <dirB>         -> reproducibility comparison
 */
declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "usage: php bench/report.php <raw-run-dir> [<raw-run-dir-b>]\n");
    exit(1);
}

$ROOT = dirname(__DIR__);

function readJson(string $file): array
{
    $raw = file_get_contents($file);
    if ($raw === false) {
        fwrite(STDERR, "cannot read $file\n");
        exit(1);
    }
    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}

function median(array $nums): float
{
    sort($nums);
    $n = count($nums);
    if ($n === 0) { return 0.0; }
    $mid = intdiv($n, 2);
    return $n % 2 ? (float) $nums[$mid] : ($nums[$mid - 1] + $nums[$mid]) / 2;
}

/** Aggregate summary.json rows into app -> scenario -> stats. */
function aggregate(array $results): array
{
    $by = [];
    foreach ($results as $row) {
        $by[$row['app']][$row['scenario']][] = $row;
    }
    $agg = [];
    foreach ($by as $app => $scenarios) {
        foreach ($scenarios as $scenario => $rows) {
            $agg[$app][$scenario] = [
                'rps' => median(array_column($rows, 'rps')),
                'rps_min' => min(array_column($rows, 'rps')),
                'rps_max' => max(array_column($rows, 'rps')),
                'p50' => median(array_column($rows, 'p50')),
                'p90' => median(array_column($rows, 'p90')),
                'p99' => median(array_column($rows, 'p99')),
                'errors' => array_sum(array_column($rows, 'errors')),
                'runs' => count($rows),
            ];
        }
    }
    return $agg;
}

function fmtRps(float $v): string
{
    return number_format($v, 0);
}

function fmtMs(?float $v): string
{
    return $v === null ? '-' : number_format($v, 2);
}

function fmtBytes(?int $b): string
{
    if ($b === null) { return '-'; }
    if ($b >= 1048576) { return number_format($b / 1048576, 1).' MB'; }
    if ($b >= 1024) { return number_format($b / 1024, 1).' KB'; }
    return $b.' B';
}

/** Rows = apps, columns = scenarios, cells = $fn(stats). */
function matrixTable(array $agg, array $apps, array $scenarios, callable $cell, string $title): string
{
    $out = "### {$title}\n\n";
    $widths = [];
    foreach ($scenarios as $s) {
        $widths[$s] = mb_strlen($s);
        foreach ($apps as $a) {
            $w = mb_strlen($cell($agg, $a['id'], $s));
            if ($w > $widths[$s]) { $widths[$s] = $w; }
        }
    }
    $nameW = 22;
    $out .= '| '.str_pad('app', $nameW).' |';
    foreach ($scenarios as $s) {
        $out .= ' '.str_pad($s, $widths[$s]).' |';
    }
    $out .= "\n|".str_repeat('-', $nameW + 2).'|';
    foreach ($scenarios as $s) {
        $out .= str_repeat('-', $widths[$s] + 2).'|';
    }
    $out .= "\n";
    foreach ($apps as $a) {
        $label = $a['name'].($a['version'] ? ' '.$a['version'] : '');
        $out .= '| '.str_pad($label, $nameW).' |';
        foreach ($scenarios as $s) {
            $out .= ' '.str_pad($cell($agg, $a['id'], $s), $widths[$s]).' |';
        }
        $out .= "\n";
    }
    return $out."\n";
}

function bars(array $agg, array $apps, string $scenario, int $width = 48): string
{
    $vals = [];
    foreach ($apps as $a) {
        $vals[$a['name']] = $agg[$a['id']][$scenario]['rps'] ?? 0;
    }
    $max = max($vals) ?: 1;
    $out = '';
    foreach ($apps as $a) {
        $v = $vals[$a['name']];
        $len = (int) round($v / $max * $width);
        $out .= sprintf("%-18s %' " . (12 - 2) . "s  %s\n", $a['name'], number_format($v), str_repeat('█', max(0, $len)));
    }
    return $out;
}

/**
 * Full single-run report.
 */
function buildReport(string $dir, array $meta, array $agg, array $apps, array $scenarioIds): string
{
    $scenarios = $scenarioIds;
    $r = "# Benchmark report — Kotchasan vs PHP frameworks\n\n";
    $ts = strtotime((string) ($meta['started_at'] ?? ''));
    $r .= "- **รันเมื่อ**: ".($ts ? date('Y-m-d H:i:s', $ts).' UTC' : (string) ($meta['started_at'] ?? '-'))."\n";
    $r .= "- **พารามิเตอร์**: {$meta['params']['RUNS']} runs × {$meta['params']['DURATION']}s, connections={$meta['params']['CONNECTIONS']} (ค่าที่รายงาน = median)\n";
    $r .= "- **เครื่องทดสอบ**: {$meta['host']['cpu']} ({$meta['host']['cores']} threads, {$meta['host']['mem_total_gb']} GB RAM, kernel {$meta['host']['kernel']})\n";
    $r .= "- **Benchmark repo commit**: `".($meta['git_commit'] ?? '-')."` ".(!empty($meta['git_dirty']) ? '(มีการแก้ไขที่ยังไม่ commit)' : '(clean)')."\n";
    $r .= "- **Load generator**: {$meta['load_tool']['name']} {$meta['load_tool']['version']}\n";
    $r .= "- **PHP**: ทุกแอปรันบน image เดียวกัน (`php:8.4-fpm-bookworm`) + php.ini/php-fpm เดียวกัน, nginx เดียวกัน, MySQL 8.4 เดียวกัน\n\n";

    $r .= "| แอป | เฟรมเวิร์กเวอร์ชัน | ขนาดในคอนเทนเนอร์ |\n|---|---|---|\n";
    foreach ($apps as $a) {
        $size = $meta['app_sizes_bytes'][$a['id']] ?? null;
        $r .= '| '.$a['name'].' | '.($a['version'] ?: 'PHP '.($meta['frameworks'][0]['version'] ?? '8.4').' (ไม่มีเฟรมเวิร์ก)').(($a['package'] ?? null) ? " (`{$a['package']}`)" : '').' | '.fmtBytes($size)." |\n";
    }
    $r .= "\n";

    $plain = 'plain-php';
    $r .= matrixTable($agg, $apps, $scenarios, function ($agg, $app, $s) {
        $st = $agg[$app][$s] ?? null;
        return $st ? fmtRps($st['rps']) : '-';
    }, 'ความเร็ว (requests/วินาที — median)');

    $r .= matrixTable($agg, $apps, $scenarios, function ($agg, $app, $s) use ($plain) {
        if (!isset($agg[$app][$s], $agg[$plain][$s]) || $agg[$plain][$s]['rps'] == 0) { return '-'; }
        return number_format($agg[$app][$s]['rps'] / $agg[$plain][$s]['rps'] * 100, 1).'%';
    }, 'เทียบกับ plain-php (100% = PHP เปล่า ไม่มีเฟรมเวิร์ก)');

    $r .= matrixTable($agg, $apps, $scenarios, function ($agg, $app, $s) {
        $st = $agg[$app][$s] ?? null;
        return $st ? fmtMs($st['p50']) : '-';
    }, 'Latency p50 (มิลลิวินาที)');

    $r .= matrixTable($agg, $apps, $scenarios, function ($agg, $app, $s) {
        $st = $agg[$app][$s] ?? null;
        return $st ? fmtMs($st['p99']) : '-';
    }, 'Latency p99 (มิลลิวินาที)');

    $errTotal = 0;
    foreach ($agg as $scens) {
        foreach ($scens as $st) { $errTotal += $st['errors']; }
    }
    $r .= "**ข้อผิดพลาดระหว่างวัด (errors + non-2xx) รวมทุกสถานการณ์: ".number_format($errTotal)."**\n\n";

    foreach ($scenarios as $s) {
        $r .= "```\n/".($s === 'db' ? 'db' : ($s === 'queries' ? 'queries?n=20' : $s))."  (rps, median)\n".bars($agg, $apps, $s)."```\n\n";
    }

    $r .= "### น้ำหนักต่อ request (จาก /info)\n\n";
    $r .= "| แอป | Peak memory | จำนวนไฟล์ที่โหลด |\n|---|---|---|\n";
    foreach ($apps as $a) {
        $i = $meta['info'][$a['id']] ?? null;
        $r .= '| '.$a['name'].' | '.($i ? fmtBytes($i['peak_memory_bytes']) : '-').' | '.($i ? $i['included_files'] : '-')."\n";
    }
    $r .= "\n";
    $r .= "---\n\nวิธีทำซ้ำ: `make build && make bench` (ดู README.md สำหรับรายละเอียดเงื่อนไขการทดสอบ)\n";
    return $r;
}

const SCENARIO_MAP = ['plaintext', 'json', 'db', 'queries', 'fortunes'];
const APP_ORDER = [
    ['id' => 'plain-php',   'name' => 'PHP (no framework)', 'pkg' => null],
    ['id' => 'kotchasan',   'name' => 'Kotchasan',          'pkg' => null],
    ['id' => 'slim',        'name' => 'Slim',               'pkg' => 'slim/slim'],
    ['id' => 'codeigniter', 'name' => 'CodeIgniter',        'pkg' => 'codeigniter4/framework'],
    ['id' => 'symfony',     'name' => 'Symfony',            'pkg' => 'symfony/framework-bundle'],
    ['id' => 'laravel',     'name' => 'Laravel',            'pkg' => 'laravel/framework'],
];

$dirA = $argv[1];
$sumA = readJson($dirA.'/summary.json');
$metaA = readJson($dirA.'/meta.json');
// Fine-grained /info metrics collected by bench/info.mjs take precedence
// over the coarse numbers captured inline by run.mjs.
if (file_exists($dirA.'/info.json')) {
    $metaA['info'] = readJson($dirA.'/info.json');
}
$aggA = aggregate($sumA['results']);

$appsPresent = array_map(fn ($f) => $f['id'], $metaA['frameworks']);
$apps = array_values(array_filter(APP_ORDER, fn ($a) => in_array($a['id'], $appsPresent, true)));
foreach ($apps as &$a) {
    $f = array_values(array_filter($metaA['frameworks'], fn ($f) => $f['id'] === $a['id']))[0];
    $a['version'] = $f['version'] ?? null;
}
unset($a);
$scenarios = array_values(array_intersect(SCENARIO_MAP, array_keys($aggA[$apps[0]['id']] ?? ['plaintext' => 0])));

if ($argc === 2) {
    // ---- single-run report ----
    $report = buildReport($dirA, $metaA, $aggA, $apps, $scenarios);
    file_put_contents($dirA.'/report.md', $report);

    $csv = "app,scenario,rps_median,rps_min,rps_max,p50_ms,p90_ms,p99_ms,errors,relative_to_plain_pct\n";
    foreach ($apps as $a) {
        foreach ($scenarios as $s) {
            $st = $aggA[$a['id']][$s] ?? null;
            if (!$st) { continue; }
            $rel = ($aggA['plain-php'][$s]['rps'] ?? 0) > 0
                ? number_format($st['rps'] / $aggA['plain-php'][$s]['rps'] * 100, 1)
                : '';
            $csv .= implode(',', [
                $a['id'], $s,
                number_format($st['rps'], 2, '.', ''),
                number_format($st['rps_min'], 2, '.', ''),
                number_format($st['rps_max'], 2, '.', ''),
                number_format($st['p50'], 3, '.', ''),
                number_format($st['p90'], 3, '.', ''),
                number_format($st['p99'], 3, '.', ''),
                (string) $st['errors'],
                $rel,
            ])."\n";
        }
    }
    file_put_contents($dirA.'/summary.csv', $csv);
    echo $report;
    echo "\nรายงานถูกเขียนที่: {$dirA}/report.md  และ  {$dirA}/summary.csv\n";
    exit(0);
}

// ---- reproducibility comparison ----
$dirB = $argv[2];
$sumB = readJson($dirB.'/summary.json');
$aggB = aggregate($sumB['results']);

echo "# ตรวจสอบความสามารถในการทำซ้ำ (reproducibility)\n\n";
echo "รัน A: {$dirA}\nรัน B: {$dirB}\n\n";
echo "| แอป | สถานการณ์ | RPS (A) | RPS (B) | ความต่าง |\n|---|---|---|---|---|\n";
$maxDiff = 0.0;
foreach ($apps as $a) {
    foreach ($scenarios as $s) {
        if (!isset($aggA[$a['id']][$s], $aggB[$a['id']][$s])) { continue; }
        $va = $aggA[$a['id']][$s]['rps'];
        $vb = $aggB[$a['id']][$s]['rps'];
        $diff = abs($va - $vb) / max($va, $vb) * 100;
        if ($diff > $maxDiff) { $maxDiff = $diff; }
        printf("| %s | %s | %s | %s | %s%% |\n", $a['name'], $s, fmtRps($va), fmtRps($vb), number_format($diff, 1));
    }
}
printf("\nความต่างมากสุดระหว่างสองรัน: %.1f%%\n", $maxDiff);
echo $maxDiff <= 10 ? "ผ่านเกณฑ์ทำซ้ำได้ (ความต่าง ≤ 10%)\n" : "ความต่างเกิน 10% — ควรตรวจสอบโหลดพื้นหลังของเครื่องระหว่างรัน\n";
exit(0);
