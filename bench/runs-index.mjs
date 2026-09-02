#!/usr/bin/env node
/**
 * Scans results/raw/* for run directories and writes results/runs.json —
 * the index the web report (index.html) uses to list available runs.
 * run.mjs calls updateRunsIndex() automatically after every benchmark.
 *
 *   node bench/runs-index.mjs
 */
import { existsSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';

export const ROOT = new URL('..', import.meta.url).pathname;

export async function updateRunsIndex() {
  const rawDir = path.join(ROOT, 'results', 'raw');
  const runs = [];
  if (existsSync(rawDir)) {
    for (const entry of readdirSync(rawDir, { withFileTypes: true })) {
      if (!entry.isDirectory()) continue;
      const dir = path.join(rawDir, entry.name);
      const summaryFile = path.join(dir, 'summary.json');
      if (!existsSync(summaryFile)) continue;
      try {
        const summary = JSON.parse(readFileSync(summaryFile, 'utf8'));
        let meta = {};
        const metaFile = path.join(dir, 'meta.json');
        if (existsSync(metaFile)) {
          meta = JSON.parse(readFileSync(metaFile, 'utf8'));
        }
        runs.push({
          dir: entry.name,
          started_at: meta.started_at ?? null,
          params: summary.params ?? null,
          git_commit: meta.git_commit ?? null,
          has_info: existsSync(path.join(dir, 'info.json')) || Boolean(meta.info),
        });
      } catch {
        // skip unreadable run
      }
    }
  }
  runs.sort((a, b) => String(b.started_at ?? b.dir).localeCompare(String(a.started_at ?? a.dir)));
  const outFile = path.join(ROOT, 'results', 'runs.json');
  writeFileSync(outFile, JSON.stringify({ updated_at: new Date().toISOString(), runs }, null, 2));
  console.log(`runs.json: ${runs.length} run(s) -> ${path.relative(ROOT, outFile)}`);
  return runs;
}

const isDirect = process.argv[1] && import.meta.url === new URL(`file://${process.argv[1]}`).href;
if (isDirect) {
  await updateRunsIndex();
}
