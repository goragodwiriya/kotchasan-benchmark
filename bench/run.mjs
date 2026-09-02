#!/usr/bin/env node
/**
 * Benchmark orchestrator.
 *
 * Protocol (defaults, override via env):
 *   RUNS=5            measured runs per app×scenario (median reported)
 *   DURATION=10       seconds per measured run
 *   WARMUP=8          seconds warmup per app before measurement
 *   CONNECTIONS=128   concurrent keep-alive connections (saturation test)
 *
 * Runs are fully interleaved (run → scenario → app) so system state drift
 * (thermal, cache, background load) affects all frameworks equally.
 */
import { execSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import autocannon from 'autocannon';
import { APPS, SCENARIOS, ROOT, baseUrl, checkEndpoint, envInt, sleep } from './lib.mjs';

const RUNS = envInt('RUNS', 5);
const DURATION = envInt('DURATION', 10);
const WARMUP = envInt('WARMUP', 8);
const CONNECTIONS = envInt('CONNECTIONS', 128);

const argList = (name) => {
  const i = process.argv.indexOf(`--${name}`);
  if (i === -1) return null;
  return process.argv[i + 1].split(',').map((s) => s.trim()).filter(Boolean);
};
const onlyApps = argList('apps');
const onlyScenarios = argList('scenarios');
const APPS_UNDER_TEST = onlyApps ? APPS.filter((a) => onlyApps.includes(a.id)) : APPS;
const SCENARIOS_UNDER_TEST = onlyScenarios ? SCENARIOS.filter((s) => onlyScenarios.includes(s.id)) : SCENARIOS;

const now = new Date();
const timestamp = now.toISOString().replace(/[:T]/g, '-').slice(0, 17).replace(/-$/, '');
const OUTDIR = path.join(ROOT, 'results', 'raw', timestamp);
const RAWDIR = path.join(OUTDIR, 'raw');
mkdirSync(RAWDIR, { recursive: true });

const log = (msg) => console.log(`[${new Date().toISOString().slice(11, 19)}] ${msg}`);

// ---------------------------------------------------------------- helpers
const sh = (cmd) => {
  try { return execSync(cmd, { cwd: ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim(); }
  catch { return null; }
};

const median = (arr) => {
  const s = [...arr].sort((a, b) => a - b);
  const m = Math.floor(s.length / 2);
  return s.length % 2 ? s[m] : (s[m - 1] + s[m]) / 2;
};

const composerLockVersion = (app, pkg) => {
  const lock = path.join(ROOT, 'apps', app.id, 'composer.lock');
  if (!pkg || !existsSync(lock)) return null;
  try {
    const packages = JSON.parse(readFileSync(lock, 'utf8')).packages;
    const found = packages.find((p) => p.name === pkg);
    return found ? found.version : null;
  } catch { return null; }
};

const errorCount = (result) => {
  let e = typeof result.errors === 'object' ? Object.values(result.errors).reduce((a, b) => a + b, 0) : (result.errors || 0);
  e += result.non2xx || 0;
  return e;
};

const runAutocannon = async (url) => {
  const result = await autocannon({ url, connections: CONNECTIONS, duration: DURATION, timeout: 10 });
  return {
    rps: result.requests.average,
    rps_total: result.requests.total,
    p50: result.latency.p50,
    p75: result.latency.p75,
    p90: result.latency.p90,
    p99: result.latency.p99,
    errors: errorCount(result),
    full: result,
  };
};

// ---------------------------------------------------------------- 1. verify
log(`Verifying ${APPS_UNDER_TEST.length} apps × ${SCENARIOS_UNDER_TEST.length} scenarios ...`);
let fortuneReference = null;
{
  const refApp = APPS.find((a) => a.id === 'plain-php');
  if (refApp && SCENARIOS_UNDER_TEST.some((s) => s.id === 'fortunes')) {
    const r = await checkEndpoint(refApp, SCENARIOS.find((s) => s.id === 'fortunes'));
    if (r.ok) fortuneReference = r.body;
  }
}
for (const app of APPS_UNDER_TEST) {
  for (const scenario of SCENARIOS_UNDER_TEST) {
    const ref = scenario.id === 'fortunes' ? fortuneReference : null;
    let ok = false, lastErr = '';
    for (let attempt = 1; attempt <= 5 && !ok; attempt++) {
      try {
        const r = await checkEndpoint(app, scenario, ref);
        ok = r.ok;
        if (!ok) lastErr = r.errors.join('; ');
      } catch (e) { lastErr = String(e.cause ?? e); }
      if (!ok) await sleep(2000 * attempt);
    }
    if (!ok) {
      console.error(`VERIFY FAILED: ${app.id} ${scenario.id} -> ${lastErr}`);
      process.exit(1);
    }
  }
}
log('Verification passed.');

// ---------------------------------------------------------------- 2. warmup
log(`Warmup: ${WARMUP}s saturation per app + DB path priming ...`);
for (const app of APPS_UNDER_TEST) {
  await autocannon({ url: baseUrl(app) + '/plaintext', connections: CONNECTIONS, duration: WARMUP, timeout: 10 });
  for (const s of ['/db', '/queries?n=20', '/fortunes']) {
    for (let i = 0; i < 10; i++) await fetch(baseUrl(app) + s).then((r) => r.text()).catch(() => {});
  }
  log(`  warmed: ${app.id}`);
  await sleep(1000);
}

// ---------------------------------------------------------------- 3. info metrics
const info = {};
for (const app of APPS_UNDER_TEST) {
  const mems = [], files = [];
  for (let i = 0; i < 5; i++) {
    try {
      const d = await (await fetch(baseUrl(app) + '/info')).json();
      mems.push(d.peak_memory_bytes);
      files.push(d.included_files);
    } catch { /* recorded as null */ }
  }
  info[app.id] = mems.length ? { peak_memory_bytes: median(mems), included_files: median(files) } : null;
}

// ---------------------------------------------------------------- 4. measured runs
log(`Measuring: ${RUNS} runs × ${DURATION}s, ${CONNECTIONS} connections, interleaved.`);
const summary = [];
for (let k = 1; k <= RUNS; k++) {
  for (const scenario of SCENARIOS_UNDER_TEST) {
    for (const app of APPS_UNDER_TEST) {
      const r = await runAutocannon(baseUrl(app) + scenario.path);
      const dir = path.join(RAWDIR, app.id, scenario.id);
      mkdirSync(dir, { recursive: true });
      writeFileSync(path.join(dir, `run${k}.json`), JSON.stringify(r.full, null, 1));
      summary.push({ app: app.id, scenario: scenario.id, run: k, rps: r.rps, p50: r.p50, p75: r.p75, p90: r.p90, p99: r.p99, errors: r.errors });
      log(`  run ${k}/${RUNS} ${app.id.padEnd(12)} ${scenario.id.padEnd(10)} ${String(Math.round(r.rps)).padStart(7)} rps  p50=${r.p50.toFixed(1)}ms  p99=${r.p99.toFixed(1)}ms  err=${r.errors}`);
      await sleep(500);
    }
    await sleep(1500);
  }
}
writeFileSync(path.join(OUTDIR, 'summary.json'), JSON.stringify({ params: { RUNS, DURATION, WARMUP, CONNECTIONS }, results: summary }, null, 1));

// ---------------------------------------------------------------- 5. environment fingerprint
const meta = {
  started_at: now.toISOString(),
  params: { RUNS, DURATION, WARMUP, CONNECTIONS },
  host: {
    cpu: os.cpus()[0]?.model ?? 'unknown',
    cores: os.cpus().length,
    mem_total_gb: Math.round(os.totalmem() / 1024 ** 3),
    kernel: os.release(),
    node: process.version,
    loadavg_start: os.loadavg(),
  },
  docker: {
    version: sh('docker --version'),
    compose: sh('docker compose version'),
    images: (() => {
      const out = sh('docker compose images --format json');
      if (!out) return null;
      return out.split('\n').filter(Boolean).map((l) => { try { return JSON.parse(l); } catch { return null; } }).filter(Boolean);
    })(),
  },
  git_commit: sh('git rev-parse HEAD'),
  git_dirty: (sh('git status --porcelain') ?? '').length > 0,
  app_sizes_bytes: Object.fromEntries(APPS_UNDER_TEST.map((a) => {
    const out = sh(`docker compose exec -T ${a.id} du -sb /app`);
    return [a.id, out ? parseInt(out, 10) : null];
  })),
  info,
  frameworks: APPS_UNDER_TEST.map((a) => {
    let version = a.pkg ? composerLockVersion(a, a.pkg) : null;
    if (a.id === 'kotchasan') {
      const load = readFileSync(path.join(ROOT, 'apps', 'kotchasan', 'Kotchasan', 'load.php'), 'utf8');
      version = load.match(/define\('VERSION', '([^']+)'/)?.[1] ?? null;
    }
    if (a.id === 'plain-php') version = null;
    return { id: a.id, name: a.name, package: a.pkg, version };
  }),
  load_tool: { name: 'autocannon', version: JSON.parse(readFileSync(new URL('./node_modules/autocannon/package.json', import.meta.url), 'utf8')).version },
};
writeFileSync(path.join(OUTDIR, 'meta.json'), JSON.stringify(meta, null, 2));

log(`Done. Raw data: ${OUTDIR}`);
log(`Generate the report with: php bench/report.php ${path.relative(ROOT, OUTDIR)}`);

// Refresh the run index used by the web report (index.html)
const { updateRunsIndex } = await import('./runs-index.mjs');
await updateRunsIndex();
