#!/usr/bin/env node
/**
 * Verification pass — every app×scenario must satisfy the uniform contract
 * (status, content-type, body shape, byte-identical fortunes HTML, UTF-8)
 * before any performance numbers are collected.
 */
import { APPS, SCENARIOS, checkEndpoint } from './lib.mjs';

const verbose = process.argv.includes('--verbose');
let failures = 0;

// Reference body for the fortunes byte-equality check comes from plain-php.
let fortuneReference = null;

for (const app of APPS) {
  for (const scenario of SCENARIOS) {
    const ref = scenario.id === 'fortunes' ? fortuneReference : null;
    let result;
    try {
      result = await checkEndpoint(app, scenario, ref);
    } catch (e) {
      result = { ok: false, errors: [String(e.cause ?? e)] };
    }
    if (scenario.id === 'fortunes' && app.id === 'plain-php' && result.ok) {
      fortuneReference = result.body;
    }
    const mark = result.ok ? 'OK ' : 'FAIL';
    console.log(`[${mark}] ${app.id.padEnd(12)} ${scenario.id.padEnd(10)}`);
    if (!result.ok) {
      failures++;
      for (const err of result.errors) console.log(`         -> ${err}`);
    } else if (verbose && scenario.id === 'fortunes') {
      console.log(`         (reference HTML ${result.body.length} bytes)`);
    }
  }
}

console.log(failures === 0
  ? `\nAll ${APPS.length * SCENARIOS.length} endpoint checks passed.`
  : `\n${failures} endpoint check(s) FAILED — fix before benchmarking.`);
process.exit(failures === 0 ? 0 : 1);
