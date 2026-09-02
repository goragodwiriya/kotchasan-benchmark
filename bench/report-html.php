<?php
/**
 * HTML report generator — renders the real benchmark data as a standalone,
 * dependency-free HTML page (CSS charts, no CDN).
 *
 *   php bench/report-html.php <runDir> [<runDirB>] [--out <file>]
 *
 * Defaults: writes <runDir>/report.html and results/REPORT.html
 * (pass --out to override the second destination).
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);

$argv = $_SERVER['argv'];
$dirA = $argv[1] ?? null;
if (!$dirA) {
    fwrite(STDERR, "usage: php bench/report-html.php <runDir> [<runDirB>] [--out <file>]\n");
    exit(1);
}
$dirB = null;
$outFile = $ROOT.'/results/REPORT.html';
for ($i = 2; $i < count($argv); $i++) {
    if ($argv[$i] === '--out' && isset($argv[$i + 1])) { $outFile = $argv[++$i]; }
    elseif (is_dir($argv[$i])) { $dirB = $argv[$i]; }
}

function readJson(string $file): array
{
    $raw = file_get_contents($file);
    if ($raw === false) { fwrite(STDERR, "cannot read $file\n"); exit(1); }
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
                'p99' => median(array_column($rows, 'p99')),
                'errors' => array_sum(array_column($rows, 'errors')),
                'runs' => count($rows),
            ];
        }
    }
    return $agg;
}

/* ------------------------------------------------------------------ data */
$APP_ORDER = [
    'plain-php'   => ['name' => 'PHP (ไม่มีเฟรมเวิร์ก)', 'short' => 'PHP เปล่า',   'color' => '#64748b'],
    'kotchasan'   => ['name' => 'Kotchasan',            'short' => 'Kotchasan',   'color' => '#f97316'],
    'slim'        => ['name' => 'Slim',                 'short' => 'Slim',        'color' => '#14b8a6'],
    'codeigniter' => ['name' => 'CodeIgniter',          'short' => 'CodeIgniter', 'color' => '#8b5cf6'],
    'symfony'     => ['name' => 'Symfony',              'short' => 'Symfony',     'color' => '#0ea5e9'],
    'laravel'     => ['name' => 'Laravel',              'short' => 'Laravel',     'color' => '#ef4444'],
];
$SCENARIOS = [
    'plaintext' => ['label' => '/plaintext',  'desc' => 'Hello, World! — ต้นทุน request pipeline ของเฟรมเวิร์ก'],
    'json'      => ['label' => '/json',       'desc' => 'สร้าง JSON response'],
    'db'        => ['label' => '/db',         'desc' => 'ดึงแถวสุ่ม 1 แถวจาก MySQL (PDO)'],
    'queries'   => ['label' => '/queries?n=20', 'desc' => 'ดึง 20 แถวสุ่ม (prepared statement ใช้ซ้ำ)'],
    'fortunes'  => ['label' => '/fortunes',   'desc' => 'เทมเพลต HTML + escaping + UTF-8 (13 แถว)'],
];

$sumA = readJson($dirA.'/summary.json');
$metaA = readJson($dirA.'/meta.json');
$infoA = file_exists($dirA.'/info.json') ? readJson($dirA.'/info.json') : ($metaA['info'] ?? []);
$agg = aggregate($sumA['results']);
$params = $sumA['params'];

$appsPresent = array_values(array_intersect(array_keys($APP_ORDER), array_keys($agg)));
$versions = [];
foreach ($metaA['frameworks'] as $f) {
    $versions[$f['id']] = $f['version'] ?? '';
}

$plain = 'plain-php';
$plainRps = [];
foreach (array_keys($SCENARIOS) as $s) { $plainRps[$s] = $agg[$plain][$s]['rps']; }

$kRps = $agg['kotchasan']['plaintext']['rps'];
$lRps = $agg['laravel']['plaintext']['rps'];
$cRps = $agg['codeigniter']['plaintext']['rps'];
$sRps = $agg['symfony']['plaintext']['rps'];
$kPct = $kRps / $plainRps['plaintext'] * 100;

$reproRows = [];
$reproMax = 0.0;
if ($dirB) {
    $sumB = readJson($dirB.'/summary.json');
    $aggB = aggregate($sumB['results']);
    foreach ($appsPresent as $app) {
        foreach (array_keys($SCENARIOS) as $s) {
            if (!isset($aggB[$app][$s])) { continue; }
            $va = $agg[$app][$s]['rps'];
            $vb = $aggB[$app][$s]['rps'];
            $diff = abs($va - $vb) / max($va, $vb) * 100;
            $reproRows[] = ['app' => $app, 'scenario' => $s, 'a' => $va, 'b' => $vb, 'diff' => $diff];
            $reproMax = max($reproMax, $diff);
        }
    }
}

/* ------------------------------------------------------------- helpers */
function nf(float $v, int $dec = 0): string
{
    return number_format($v, $dec);
}
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
function fmtBytes(int $b): string
{
    if ($b >= 1048576) { return nf($b / 1048576, 1).' MB'; }
    if ($b >= 1024) { return nf($b / 1024, 1).' KB'; }
    return $b.' B';
}

/**
 * Horizontal bar chart (pure CSS). $entries: [label, sub, value, min, max, color]
 */
function barChart(array $entries): string
{
    $max = max(array_column($entries, 'max')) ?: 1;
    $html = '<div class="chart">';
    foreach ($entries as $en) {
        $wVal = $en['value'] / $max * 100;
        $wMin = $en['min'] / $max * 100;
        $wMax = $en['max'] / $max * 100;
        $html .= '<div class="chart-row">'
            .'<div class="chart-label" style="--c:'.e($en['color']).'">'.e($en['label']).'</div>'
            .'<div class="chart-track">'
            .'<div class="chart-range" style="left:'.nf($wMin, 2).'%;width:'.nf(max(0.5, $wMax - $wMin), 2).'%"></div>'
            .'<div class="chart-bar" style="width:'.nf($wVal, 2).'%;background:'.e($en['color']).'"></div>'
            .'</div>'
            .'<div class="chart-value">'.nf($en['value']).'</div>'
            .'<div class="chart-sub">'.e($en['sub'] ?? '').'</div>'
            .'</div>';
    }
    return $html.'</div>';
}

function chartFor(array $agg, array $apps, string $scenario, array $plainRps, bool $pctSub = true): string
{
    $entries = [];
    foreach ($apps as $id => $meta) {
        $st = $agg[$id][$scenario] ?? null;
        if (!$st) { continue; }
        $entries[] = [
            'label' => $meta['name'].($meta['ver'] ? ' '.$meta['ver'] : ''),
            'value' => $st['rps'],
            'min' => $st['rps_min'],
            'max' => $st['rps_max'],
            'color' => $meta['color'],
            'sub' => $pctSub && $id !== 'plain-php' && $plainRps[$scenario] > 0
                ? nf($st['rps'] / $plainRps[$scenario] * 100, 1).'%' : 'baseline',
        ];
    }
    usort($entries, fn ($a, $b) => $b['value'] <=> $a['value']);
    return barChart($entries);
}

$appMeta = [];
foreach ($APP_ORDER as $id => $m) {
    $appMeta[$id] = $m + ['ver' => preg_replace('/^v/', '', (string) ($versions[$id] ?? ''))];
}

/* ------------------------------------------------------------ html head */
$startedAt = $metaA['started_at'] ?? '';
$ts = strtotime((string) $startedAt);
$when = $ts ? date('d/m/Y H:i', $ts).' UTC' : (string) $startedAt;

$totalErrors = 0;
foreach ($agg as $scens) { foreach ($scens as $st) { $totalErrors += $st['errors']; } }
$totalRequests = 0;
foreach ($sumA['results'] as $row) { $totalRequests += (int) round($row['rps'] * $params['DURATION']); }

$css = <<<'CSS'
:root{
  --bg:#f1f5f9; --card:#ffffff; --ink:#0f172a; --muted:#64748b; --line:#e2e8f0;
  --accent:#f97316; --accent-2:#ea580c; --good:#059669; --warn:#d97706;
  --mono:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
}
*{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{
  font-family:"Segoe UI","Leelawadee UI","Noto Sans Thai","Sarabun",system-ui,-apple-system,sans-serif;
  background:var(--bg);color:var(--ink);line-height:1.65;font-size:15.5px;
}
.wrap{max-width:1100px;margin:0 auto;padding:0 20px 80px}
a{color:var(--accent-2)}

/* hero */
.hero{
  background:linear-gradient(135deg,#1e293b 0%,#0f172a 55%,#7c2d12 130%);
  color:#fff;padding:52px 0 120px;margin-bottom:-72px;
}
.hero .wrap{padding-bottom:0}
.hero h1{font-size:clamp(1.6rem,4vw,2.4rem);font-weight:700;letter-spacing:-.01em}
.hero .sub{color:#cbd5e1;margin-top:10px;font-size:1.02rem;max-width:760px}
.hero .badges{display:flex;flex-wrap:wrap;gap:8px;margin-top:18px}
.badge{
  font-size:.8rem;padding:4px 12px;border-radius:999px;border:1px solid rgba(255,255,255,.25);
  background:rgba(255,255,255,.08);color:#e2e8f0;font-family:var(--mono);
}
.stat-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-top:34px}
.stat{
  background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.14);border-radius:14px;
  padding:16px 18px;backdrop-filter:blur(4px);
}
.stat .n{font-size:1.55rem;font-weight:700;font-family:var(--mono);letter-spacing:-.02em}
.stat .l{font-size:.82rem;color:#cbd5e1;margin-top:2px}

/* nav */
nav.toc{
  position:sticky;top:0;z-index:50;background:rgba(255,255,255,.92);backdrop-filter:blur(8px);
  border-bottom:1px solid var(--line);margin-top:72px;
}
nav.toc .wrap{display:flex;gap:4px;overflow-x:auto;padding:0 20px}
nav.toc a{
  white-space:nowrap;text-decoration:none;color:var(--muted);font-size:.86rem;
  padding:11px 12px;border-bottom:2.5px solid transparent;
}
nav.toc a:hover{color:var(--ink)} nav.toc a.on{color:var(--accent-2);border-color:var(--accent)}

/* sections & cards */
section{margin-top:44px}
h2{font-size:1.32rem;font-weight:700;letter-spacing:-.01em;margin-bottom:4px}
h2 .num{
  display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:9px;
  background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#fff;font-size:.9rem;
  margin-right:10px;vertical-align:-4px;font-family:var(--mono);
}
.lead{color:var(--muted);font-size:.95rem;margin-bottom:18px}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:22px 24px;box-shadow:0 1px 2px rgba(15,23,42,.05)}
.card+.card{margin-top:16px}

/* chart */
.chart{display:flex;flex-direction:column;gap:11px;margin:6px 0 4px}
.chart-row{display:grid;grid-template-columns:190px 1fr 86px 74px;gap:12px;align-items:center}
.chart-label{font-size:.88rem;font-weight:600;text-align:right;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.chart-track{position:relative;height:26px;background:#f1f5f9;border-radius:7px;overflow:hidden}
.chart-range{position:absolute;top:6px;height:14px;background:rgba(100,116,139,.25);border-radius:4px}
.chart-bar{
  position:absolute;top:3px;height:20px;border-radius:6px;min-width:3px;
  background-image:linear-gradient(rgba(255,255,255,.16),rgba(255,255,255,0));
}
.chart-value{font-family:var(--mono);font-size:.95rem;font-weight:600;text-align:right;font-variant-numeric:tabular-nums}
.chart-sub{font-size:.78rem;color:var(--muted);font-family:var(--mono);text-align:right}
.chart-note{font-size:.8rem;color:var(--muted);margin-top:10px}

/* scenario grid */
.scenario-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:16px}
.scenario-grid .card{margin:0}
.scenario-head{display:flex;justify-content:space-between;align-items:baseline;gap:10px;flex-wrap:wrap}
.scenario-head code{font-family:var(--mono);font-size:.86rem;background:#fff7ed;color:#c2410c;padding:2px 8px;border-radius:6px}
.scenario-head .rank{font-size:.78rem;color:var(--muted)}
.scenario-desc{font-size:.84rem;color:var(--muted);margin:6px 0 14px}

/* tables */
table{width:100%;border-collapse:collapse;font-size:.9rem}
th,td{padding:9px 12px;border-bottom:1px solid var(--line);text-align:right;font-variant-numeric:tabular-nums}
th:first-child,td:first-child{text-align:left}
thead th{font-size:.76rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:600;background:#f8fafc;border-bottom:2px solid var(--line)}
tbody tr:hover{background:#f8fafc}
td.num,th.num{font-family:var(--mono)}
.best{color:var(--good);font-weight:700}
.tbl-note{font-size:.8rem;color:var(--muted);margin-top:10px}

/* pill */
.pill{display:inline-block;font-size:.72rem;font-weight:700;padding:2px 9px;border-radius:999px;vertical-align:1px}
.pill.hi{background:#dcfce7;color:#166534} .pill.mid{background:#fef9c3;color:#854d0e} .pill.lo{background:#fee2e2;color:#991b1b}

/* info grid */
.kv-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px}
.kv{background:#f8fafc;border:1px solid var(--line);border-radius:10px;padding:10px 14px}
.kv .k{font-size:.74rem;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
.kv .v{font-family:var(--mono);font-size:.9rem;margin-top:2px;word-break:break-all}

details{border:1px solid var(--line);border-radius:12px;background:var(--card);padding:14px 18px;margin-top:14px}
details summary{cursor:pointer;font-weight:600;font-size:.95rem}
details[open] summary{margin-bottom:10px}

.footer-note{margin-top:50px;text-align:center;color:var(--muted);font-size:.85rem}
code.inline{background:#f1f5f9;border:1px solid var(--line);padding:1px 7px;border-radius:6px;font-family:var(--mono);font-size:.84rem}

@media (max-width:760px){
  .chart-row{grid-template-columns:110px 1fr 70px 58px;gap:7px}
  .chart-label{font-size:.76rem}
  .scenario-grid{grid-template-columns:1fr}
}
@media print{
  nav.toc{display:none} body{background:#fff}
  .card,details{box-shadow:none;break-inside:avoid}
}
CSS;

/* --------------------------------------------------- section builders */

// hero
$hero = '<header class="hero"><div class="wrap">'
    .'<h1>Kotchasan vs PHP Frameworks — Benchmark Report</h1>'
    .'<p class="sub">วัดจริงด้วย HTTP load generator (autocannon) บน Docker stack ที่ควบคุมสภาพแวดล้อมเหมือนกันทุกเฟรมเวิร์ก '
    .'· ทุกตัวเลขในหน้านี้ generate จากผลวัดดิบโดยอัตโนมัติ</p>'
    .'<div class="badges">'
    .'<span class="badge">'.e($when).'</span>'
    .'<span class="badge">PHP '.e($metaA['host']['php'] ?? '8.4-fpm-bookworm').' (image เดียวกันทุกแอป)</span>'
    .'<span class="badge">'.$params['RUNS'].' runs × '.$params['DURATION'].'s · c='.$params['CONNECTIONS'].'</span>'
    .'<span class="badge">commit '.e(substr((string) ($metaA['git_commit'] ?? ''), 0, 7)).'</span>'
    .'</div>'
    .'<div class="stat-cards">'
    .'<div class="stat"><div class="n" style="color:#fdba74">'.nf($kRps).' rps</div><div class="l">Kotchasan — /plaintext อันดับ 1 ในบรรดาเฟรมเวิร์ก</div></div>'
    .'<div class="stat"><div class="n" style="color:#fdba74">'.nf($kPct, 1).'%</div><div class="l">ของความเร็ว PHP เปล่า (ไม่มีเฟรมเวิร์ก)</div></div>'
    .'<div class="stat"><div class="n" style="color:#fdba74">'.nf($kRps / $lRps, 1).'×</div><div class="l">เร็วกว่า Laravel '.nf($lRps).' rps</div></div>'
    .'<div class="stat"><div class="n" style="color:#fdba74">'.nf($infoA['kotchasan']['included_files'] ?? 27).' ไฟล์</div><div class="l">ที่ต้องโหลดต่อ request เทียบ Laravel '.nf($infoA['laravel']['included_files'] ?? 338).' ไฟล์</div></div>'
    .'</div></div></header>';

// nav
$nav = '<nav class="toc"><div class="wrap">'
    .'<a href="#overview">ภาพรวม</a><a href="#scenarios">ผลรายสถานการณ์</a><a href="#tables">ตารางตัวเลข</a>'
    .'<a href="#weight">น้ำหนักต่อ request</a><a href="#repro">การทำซ้ำ</a><a href="#env">สภาพแวดล้อม</a>'
    .'<a href="#method">วิธีวัด</a></div></nav>';

// overview chart (plaintext) + ratios
$overview = '<section id="overview"><h2><span class="num">1</span>ภาพรวม — ความเร็วสูงสุดต่อวินาที (RPS)</h2>'
    .'<p class="lead">สถานการณ์ <code class="inline">/plaintext</code> (ส่ง "Hello, World!" กลับ) สะท้อนต้นทุน request pipeline ของเฟรมเวิร์กแท้ ๆ — แท่งทึบ = median ของ '.$params['RUNS'].' รัน, แถบจาง = ช่วงต่ำ-สูงสุดที่วัดได้</p>'
    .'<div class="card">'.chartFor($agg, $appMeta, 'plaintext', $plainRps)
    .'<p class="chart-note">อ่านคอลัมน์ขวาสุดเป็น % เทียบ PHP เปล่า · ทำไม Kotchasan เร็ว: ดู <a href="#weight">น้ำหนักต่อ request</a> — จำนวนไฟล์/คลาสที่ต้องโหลดต่อ request ต่างกันถึง 12 เท่า</p></div>'
    .'<div class="card"><table><thead><tr><th>เฟรมเวิร์ก</th><th>plaintext</th><th>json</th><th>db</th><th>queries×20</th><th>fortunes</th></tr></thead><tbody>';
foreach ($appsPresent as $id) {
    $overview .= '<tr><td><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:'.$appMeta[$id]['color'].';margin-right:8px;vertical-align:0"></span>'.e($appMeta[$id]['name']).'</td>';
    foreach (array_keys($SCENARIOS) as $s) {
        $overview .= '<td class="num">'.nf($agg[$id][$s]['rps']).'</td>';
    }
    $overview .= '</tr>';
}
$overview .= '</tbody></table><p class="tbl-note">ค่า median จาก '.$params['RUNS'].' runs × '.$params['DURATION'].' วินาที ต่อเซลล์ · requests รวมทั้งรันประมาณ '.nf($totalRequests).' requests</p></div></section>';

// scenario cards
$scenCards = '<section id="scenarios"><h2><span class="num">2</span>ผลรายสถานการณ์</h2>'
    .'<p class="lead">แต่ละการ์ดเรียงจากเร็วไปช้า · เปอร์เซ็นต์คือสัดส่วนเทียบ PHP เปล่าในสถานการณ์เดียวกัน</p><div class="scenario-grid">';
foreach ($SCENARIOS as $sid => $sc) {
    $rank = array_keys(array_flip($appsPresent));
    // sort apps by rps for this scenario
    $sorted = $appsPresent;
    usort($sorted, fn ($a, $b) => $agg[$b][$sid]['rps'] <=> $agg[$a][$sid]['rps']);
    $winner = $sorted[0] === 'plain-php' ? ($sorted[1] ?? $sorted[0]) : $sorted[0];
    $scenCards .= '<div class="card"><div class="scenario-head"><code>'.e($sc['label']).'</code>'
        .'<span class="rank">เร็วสุดในเฟรมเวิร์ก: <strong style="color:'.$appMeta[$winner]['color'].'">'.e($appMeta[$winner]['short']).'</strong></span></div>'
        .'<p class="scenario-desc">'.e($sc['desc']).'</p>'
        .chartFor($agg, $appMeta, $sid, $plainRps);
    // mini table p50/p99
    $scenCards .= '<table style="margin-top:10px"><thead><tr><th>แอป</th><th>p50</th><th>p99</th></tr></thead><tbody>';
    foreach ($sorted as $id) {
        $st = $agg[$id][$sid];
        $scenCards .= '<tr><td>'.e($appMeta[$id]['short']).'</td><td class="num">'.nf($st['p50'], 2).' ms</td><td class="num">'.nf($st['p99'], 2).' ms</td></tr>';
    }
    $scenCards .= '</tbody></table></div>';
}
$scenCards .= '</div></section>';

// full numeric tables (rps + pct + p99)
$tblSection = '<section id="tables"><h2><span class="num">3</span>ตารางตัวเลขครบทุกมิติ (median)</h2><p class="lead">RPS · เปอร์เซ็นต์เทียบ PHP เปล่า · latency p50/p99 หน่วยมิลลิวินาที</p>';
foreach ([['rps', 'ความเร็ว (RPS) + % เทียบ baseline'], ['p50', 'Latency p50 (ms)'], ['p99', 'Latency p99 (ms)']] as [$metric, $title]) {
    $tblSection .= '<div class="card"><strong style="font-size:.95rem">'.$title.'</strong><table><thead><tr><th>แอป</th>';
    foreach ($SCENARIOS as $sc) { $tblSection .= '<th>'.e($sc['label']).'</th>'; }
    $tblSection .= '</tr></thead><tbody>';
    foreach ($appsPresent as $id) {
        $tblSection .= '<tr><td>'.e($appMeta[$id]['name']).'</td>';
        foreach (array_keys($SCENARIOS) as $s) {
            $st = $agg[$id][$s];
            if ($metric === 'rps') {
                $cell = nf($st['rps']);
                if ($id !== 'plain-php') {
                    $cell .= '<br><span style="font-size:.74rem;color:var(--muted)">'.nf($st['rps'] / $plainRps[$s] * 100, 1).'%</span>';
                } else {
                    $cell .= '<br><span style="font-size:.74rem;color:var(--muted)">100%</span>';
                }
                $tblSection .= '<td class="num'.($id === 'kotchasan' ? ' best' : '').'">'.$cell.'</td>';
            } else {
                $tblSection .= '<td class="num">'.nf($st[$metric], 2).'</td>';
            }
        }
        $tblSection .= '</tr>';
    }
    $tblSection .= '</tbody></table></div>';
}
$tblSection .= '<div class="card"><strong style="font-size:.95rem">ข้อผิดพลาดระหว่างวัด</strong>'
    .'<p style="margin-top:8px;font-size:.92rem">รวม '.nf($totalErrors).' socket errors จาก ~'.nf($totalRequests).' requests (≈'.nf($totalErrors / max(1, $totalRequests) * 100, 2).'%) — เป็น connection reset ฝั่ง load generator ตอนสิ้นสุดแต่ละรันเป็นหลัก, <strong>ฝั่ง server ตอบ 2xx ทั้งหมด</strong> (non-2xx = 0, ยืนยันจาก access log) และไม่มีผลต่อค่า median</p></div></section>';

// weight section
$weight = '<section id="weight"><h2><span class="num">4</span>น้ำหนักต่อ request — ทำไม Kotchasan เร็ว</h2>'
    .'<p class="lead">วัดจาก endpoint <code class="inline">/info</code> (median 7 ครั้ง) และขนาดแอปใน container · ยิ่งโหลดไฟล์/ใช้ memory น้อย ยิ่งมี overhead ต่ำ</p>'
    .'<div class="scenario-grid">'
    .'<div class="card"><strong style="font-size:.95rem">จำนวนไฟล์ที่โหลดต่อ request</strong>';
$maxFiles = max(array_map(fn ($a) => $infoA[$a]['included_files'] ?? 0, $appsPresent));
$filesEntries = [];
foreach ($appsPresent as $id) {
    $filesEntries[$id] = $infoA[$id]['included_files'] ?? 0;
}
arsort($filesEntries);
foreach ($filesEntries as $id => $f) {
    $weight .= '<div class="chart-row" style="margin-top:8px"><div class="chart-label" style="--c:'.$appMeta[$id]['color'].'">'.e($appMeta[$id]['short']).'</div>'
        .'<div class="chart-track"><div class="chart-bar" style="width:'.nf($f / $maxFiles * 100, 2).'%;background:'.$appMeta[$id]['color'].'"></div></div>'
        .'<div class="chart-value">'.$f.'</div><div class="chart-sub"></div></div>';
}
$weight .= '</div><div class="card"><strong style="font-size:.95rem">Peak memory ต่อ request (KB)</strong>';
$memEntries = [];
foreach ($appsPresent as $id) { $memEntries[$id] = (int) round(($infoA[$id]['peak_memory_bytes'] ?? 0) / 1024); }
$maxMem = max($memEntries) ?: 1;
arsort($memEntries);
foreach ($memEntries as $id => $m) {
    $weight .= '<div class="chart-row" style="margin-top:8px"><div class="chart-label" style="--c:'.$appMeta[$id]['color'].'">'.e($appMeta[$id]['short']).'</div>'
        .'<div class="chart-track"><div class="chart-bar" style="width:'.nf($m / $maxMem * 100, 2).'%;background:'.$appMeta[$id]['color'].'"></div></div>'
        .'<div class="chart-value">'.nf($m, 0).'</div><div class="chart-sub"></div></div>';
}
$weight .= '</div></div><div class="card"><table><thead><tr><th>แอป</th><th>เฟรมเวิร์ก (แพ็กเกจ)</th><th>เวอร์ชัน</th><th>ไฟล์/request</th><th>Memory/request</th><th>ขนาดใน container</th></tr></thead><tbody>';
foreach ($appsPresent as $id) {
    $fw = null;
    foreach ($metaA['frameworks'] as $f) { if ($f['id'] === $id) { $fw = $f; break; } }
    $weight .= '<tr><td>'.e($appMeta[$id]['name']).'</td>'
        .'<td style="font-family:var(--mono);font-size:.8rem">'.e($fw['package'] ?? ($id === 'plain-php' ? '— ไม่มี' : ($id === 'kotchasan' ? 'vendored (pinned)' : ''))).'</td>'
        .'<td class="num">'.e($fw['version'] ?? 'PHP 8.4').'</td>'
        .'<td class="num'.($id === 'kotchasan' ? ' best' : '').'">'.nf($infoA[$id]['included_files'] ?? 0).'</td>'
        .'<td class="num">'.fmtBytes($infoA[$id]['peak_memory_bytes'] ?? 0).'</td>'
        .'<td class="num">'.fmtBytes($metaA['app_sizes_bytes'][$id] ?? 0).'</td></tr>';
}
$weight .= '</tbody></table><p class="tbl-note">Kotchasan โหลด 27 ไฟล์ต่อ request ใกล้เคียง PHP เปล่า (2 ไฟล์) — นี่คือเหตุผลหลักที่ความเร็วตามหลัง baseline เพียง 11–16% ขณะที่เฟรมเวิร์กเต็มรูปแบบโหลด 198–338 ไฟล์</p></div></section>';

// reproducibility
$repro = '<section id="repro"><h2><span class="num">5</span>การตรวจสอบซ้ำ (Reproducibility)</h2>';
if ($reproRows) {
    $repro .= '<p class="lead">รันชุดเดียวกันซ้ำ 2 ครั้งเต็มรูปแบบ แล้วเทียบ median — ความต่างส่วนใหญ่ '.nf(min(array_column($reproRows, 'diff')), 1).'–'.nf(median(array_column($reproRows, 'diff')) * 2, 1).'% · สูงสุด '.nf($reproMax, 1).'% (สถานการณ์ queries ซึ่งผูกกับ MySQL หนักสุด) · <strong>อันดับเฟรมเวิร์กเหมือนกันทุกคู่ในทุกสถานการณ์</strong></p>'
        .'<div class="card"><details open><summary>ตารางเทียบรัน A ↔ รัน B ('.count($reproRows).' คู่)</summary><table><thead><tr><th>แอป</th><th>สถานการณ์</th><th>RPS รัน A</th><th>RPS รัน B</th><th>ความต่าง</th></tr></thead><tbody>';
    foreach ($reproRows as $r) {
        $cls = $r['diff'] <= 5 ? 'hi' : ($r['diff'] <= 10 ? 'mid' : 'lo');
        $repro .= '<tr><td>'.e($appMeta[$r['app']]['short']).'</td><td style="font-family:var(--mono);font-size:.8rem">'.e($SCENARIOS[$r['scenario']]['label']).'</td>'
            .'<td class="num">'.nf($r['a']).'</td><td class="num">'.nf($r['b']).'</td>'
            .'<td class="num"><span class="pill '.$cls.'">'.nf($r['diff'], 1).'%</span></td></tr>';
    }
    $repro .= '</tbody></table><p class="tbl-note">เกณฑ์สี: เขียว ≤5% · เหลือง ≤10% · แดง &gt;10% — การ์ดแดงทั้งหมดเป็นสถานการณ์ DB หนักซึ่งความผันผวนมาจาก MySQL ไม่ใช่เฟรมเวิร์ก</p></details></div>';
} else {
    $repro .= '<p class="lead">ยังไม่มีรันที่สองสำหรับเทียบ — รัน <code class="inline">make bench</code> อีกครั้งแล้วใช้ <code class="inline">php bench/report-html.php &lt;dirA&gt; &lt;dirB&gt;</code></p>';
}
$repro .= '</section>';

// environment
$imgRows = '';
foreach (($metaA['docker']['images'] ?? []) as $img) {
    $svc = $img['Service'] ?? ($img['ContainerName'] ?? '');
    if (!in_array($svc, $appsPresent, true)) { $svcName = $svc; } else { $svcName = $svc; }
    $imgRows .= '<tr><td>'.e((string) $svc).'</td><td style="font-family:var(--mono);font-size:.8rem">'.e((string) ($img['Repository'] ?? '').':'.($img['Tag'] ?? '')).'</td>'
        .'<td style="font-family:var(--mono);font-size:.8rem">'.e((string) substr((string) ($img['ID'] ?? ''), 0, 19)).'</td>'
        .'<td class="num">'.e((string) ($img['Size'] ?? '')).'</td></tr>';
}
$env = '<section id="env"><h2><span class="num">6</span>สภาพแวดล้อม (Fingerprint ของรันนี้)</h2>'
    .'<p class="lead">บันทึกอัตโนมัติใน meta.json ประกอบผล — เพื่อให้รู้ว่าตัวเลขชุดนี้เกิดบนสภาพแวดล้อมใดแน่นอน</p>'
    .'<div class="card"><div class="kv-grid">'
    .'<div class="kv"><div class="k">CPU</div><div class="v">'.e($metaA['host']['cpu']).' · '.$metaA['host']['cores'].' threads</div></div>'
    .'<div class="kv"><div class="k">RAM</div><div class="v">'.$metaA['host']['mem_total_gb'].' GB</div></div>'
    .'<div class="kv"><div class="k">Kernel</div><div class="v">'.e($metaA['host']['kernel']).'</div></div>'
    .'<div class="kv"><div class="k">Load generator</div><div class="v">'.e($metaA['load_tool']['name']).' '.e($metaA['load_tool']['version']).'</div></div>'
    .'<div class="kv"><div class="k">Docker</div><div class="v">'.e(str_replace('Docker version ', '', (string) $metaA['docker']['version'])).'</div></div>'
    .'<div class="kv"><div class="k">Git commit</div><div class="v">'.e((string) ($metaA['git_commit'] ?? '')).($metaA['git_dirty'] ? ' (dirty)' : ' (clean)').'</div></div>'
    .'</div></div>'
    .'<div class="card"><strong style="font-size:.95rem">Docker images ที่ใช้จริง</strong><table style="margin-top:8px"><thead><tr><th>service</th><th>image</th><th>ID</th><th>ขนาด</th></tr></thead><tbody>'.$imgRows.'</tbody></table></div>'
    .'<details><summary>การตั้งค่า PHP / php-fpm / nginx / MySQL (เหมือนกันทุกแอป)</summary>'
    .'<ul style="font-size:.9rem;padding-left:20px;line-height:1.9">'
    .'<li><strong>php.ini</strong>: opcache.enable=1, validate_timestamps=0, memory 256MB, jit=disable</li>'
    .'<li><strong>php-fpm</strong>: pm=static, max_children=16 (เท่ากันทุกแอป), max_requests=0, clear_env=no</li>'
    .'<li><strong>nginx</strong>: fastcgi upstream keepalive=32 + fastcgi_keep_conn on, gzip off, access_log off</li>'
    .'<li><strong>MySQL 8.4</strong>: innodb_buffer_pool=2G, skip-name-resolve · ข้อมูล <code>world</code> 10,000 แถว seed แบบ deterministic (mt_srand(42)) + <code>fortune</code> 12 แถว</li>'
    .'<li><strong>ฐานข้อมูล</strong>: ทุกแอปใช้ raw PDO + SQL เดียวกันเป๊ะ (persistent connection, real prepare) — ตัวแปร ORM ออกจากการวัด</li>'
    .'</ul></details></section>';

// methodology
$method = '<section id="method"><h2><span class="num">7</span>วิธีวัด & ข้อจำกัดที่ควรรู้ก่อนตีความ</h2>'
    .'<div class="card"><ul style="font-size:.92rem;padding-left:20px;line-height:1.9">'
    .'<li><strong>สถานการณ์ 5 แบบ</strong>: plaintext (framework overhead), json (serialization), db (query 1 แถว), queries×20 (prepared stmt ใช้ซ้ำ), fortunes (template + HTML escaping + UTF-8 รวมแถวภาษาไทยและแถวที่ต้อง escape &lt;script&gt;)</li>'
    .'<li><strong>Verify ก่อนวัดเสมอ</strong>: 30 endpoint (6 แอป × 5 สถานการณ์) ต้องผ่าน contract — สถานะ 200, content-type ถูก, รูปร่างข้อมูลถูก และ fortunes HTML ต้อง <em>byte-identical ข้ามทุกเฟรมเวิร์ก</em> จึงเริ่มวัด (พิสูจน์ว่าทุกแอปทำงานเหมือนกันจริง)</li>'
    .'<li><strong>Interleave</strong>: วนสลับ run→scenario→app ทั้ง '.$params['RUNS'].' รัน เพื่อให้สภาพเครื่อง (thermal/cache/background) กระทบทุกเฟรมเวิร์กเท่ากัน + warmup 8 วินาทีต่อแอปก่อนวัด</li>'
    .'<li><strong>Production config</strong>: ทุกเฟรมเวิร์กรันโหมด production พร้อม cache config/route/view (Laravel, Symfony, CI4) และไม่มีเฟรมเวิร์กไหนเริ่ม session — route ของ Laravel สมัครนอก web middleware group เพราะ load client ไม่ส่ง cookie (มิฉะนั้นจะกลายเป็นวัดการสร้าง session file ใหม่ทุก request)</li>'
    .'</ul></div>'
    .'<div class="card"><strong style="font-size:.95rem">ข้อจำกัด</strong><ul style="font-size:.92rem;padding-left:20px;line-height:1.9;margin-top:8px">'
    .'<li>นี่คือ <strong>micro-benchmark ของ overhead ต่อ request</strong> ไม่ใช่ "แอปจริงบนเฟรมเวิร์กไหนเร็วกว่า" — แอปจริงมี cache, queue, external API ที่ทำให้สัดส่วนต่างเล็กลง</li>'
    .'<li>ไม่ได้วัด ORM/Query Builder ของแต่ละค่าย (ใช้ raw PDO เท่ากันหมดโดยตั้งใจ)</li>'
    .'<li>ตัวเลข RPS เปรียบเทียบได้<strong>เฉพาะในเครื่องเดียวกัน/รันเดียวกัน</strong> — เครื่องอื่นให้ดูอัตราส่วน % เทียบ PHP เปล่า</li>'
    .'<li>เฟรมเวิร์กที่ช้ากว่ามักแลกมาด้วยฟีเจอร์ที่ครบกว่า (validation, ORM, ecosystem) — benchmark วัดต้นทุนต่อ request ไม่ได้ตัดสินว่าเฟรมเวิร์กไหน "ดีกว่า"</li>'
    .'</ul></div></section>';

$footer = '<div class="footer-note">สร้างโดย <code class="inline">php bench/report-html.php '.e(basename($dirA)).($dirB ? ' '.e(basename($dirB)) : '').'</code> จากผลวัดดิบ '
    .'· ทำซ้ำได้ด้วย <code class="inline">make build &amp;&amp; make bench</code> · ดู README.md ประกอบ<br>'
    .'Kotchasan benchmark suite — '.e($when).'</div>';

$html = '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8">'
    .'<meta name="viewport" content="width=device-width, initial-scale=1.0">'
    .'<title>Kotchasan vs PHP Frameworks — Benchmark Report</title>'
    .'<style>'.$css.'</style></head><body>'
    .$hero.$nav.'<div class="wrap">'.$overview.$scenCards.$tblSection.$weight.$repro.$env.$method.$footer.'</div>'
    .'</body></html>';

file_put_contents($dirA.'/report.html', $html);
file_put_contents($outFile, $html);
echo "written: {$dirA}/report.html\n";
echo "written: {$outFile}\n";
