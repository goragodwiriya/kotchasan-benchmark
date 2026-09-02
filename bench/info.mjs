#!/usr/bin/env node
/**
 * Collect /info metrics (peak memory per request, included files) for every
 * app into a raw run directory. /info is not part of the load loop, so this
 * can be run any time the stack is up.
 *
 *   node bench/info.mjs results/raw/<tag>
 */
import { writeFileSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { APPS, ROOT, baseUrl } from './lib.mjs';

const dir = process.argv[2];
if (!dir) {
    console.error('usage: node bench/info.mjs results/raw/<tag>');
    process.exit(1);
}

const median = (a) => [...a].sort((x, y) => x - y)[Math.floor(a.length / 2)];

const info = {};
for (const app of APPS) {
    const mems = [], files = [];
    for (let i = 0; i < 7; i++) {
        try {
            const d = await (await fetch(baseUrl(app) + '/info')).json();
            mems.push(d.peak_memory_bytes);
            files.push(d.included_files);
        } catch { /* skip */ }
    }
    info[app.id] = mems.length
        ? { peak_memory_bytes: median(mems), included_files: median(files) }
        : null;
    console.log(`${app.id.padEnd(12)} mem=${info[app.id]?.peak_memory_bytes} files=${info[app.id]?.included_files}`);
}

writeFileSync(path.join(ROOT, dir, 'info.json'), JSON.stringify(info, null, 2));
console.log(`written: ${dir}/info.json`);
