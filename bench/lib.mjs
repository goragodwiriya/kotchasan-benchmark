/**
 * Shared definitions for the benchmark suite: apps under test, scenarios,
 * and helpers used by verify.mjs / run.mjs.
 */
export const ROOT = new URL('..', import.meta.url).pathname;

export const APPS = [
  { id: 'plain-php',   port: 18081, name: 'PHP (no framework)', pkg: null },
  { id: 'kotchasan',   port: 18082, name: 'Kotchasan',          pkg: null },
  { id: 'slim',        port: 18083, name: 'Slim',               pkg: 'slim/slim' },
  { id: 'codeigniter', port: 18084, name: 'CodeIgniter',        pkg: 'codeigniter4/framework' },
  { id: 'symfony',     port: 18085, name: 'Symfony',            pkg: 'symfony/framework-bundle' },
  { id: 'laravel',     port: 18086, name: 'Laravel',            pkg: 'laravel/framework' },
];

export const SCENARIOS = [
  { id: 'plaintext', path: '/plaintext',     desc: 'Hello, World!  (framework request overhead)' },
  { id: 'json',      path: '/json',          desc: 'JSON serialization' },
  { id: 'db',        path: '/db',            desc: '1 random DB row via PDO' },
  { id: 'queries',   path: '/queries?n=20',  desc: '20 random DB rows via PDO' },
  { id: 'fortunes',  path: '/fortunes',      desc: 'HTML template + escaping (12 rows)' },
];

export const envInt = (key, def) => {
  const v = parseInt(process.env[key] ?? '', 10);
  return Number.isFinite(v) && v > 0 ? v : def;
};

export const baseUrl = (app) => `http://127.0.0.1:${app.port}`;

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * Fetch and validate one endpoint against the uniform scenario contract.
 * Returns { ok, errors[] }.
 */
export async function checkEndpoint(app, scenario, reference = null) {
  const errors = [];
  const res = await fetch(baseUrl(app) + scenario.path, { redirect: 'manual' });
  const body = await res.text();
  const ct = (res.headers.get('content-type') ?? '').toLowerCase();

  if (res.status !== 200) errors.push(`status ${res.status} (body: ${body.slice(0, 120)})`);

  switch (scenario.id) {
    case 'plaintext':
      if (!ct.startsWith('text/plain')) errors.push(`content-type ${ct}`);
      if (body !== 'Hello, World!') errors.push(`body mismatch: ${body.slice(0, 60)}`);
      break;
    case 'json':
      if (!ct.startsWith('application/json')) errors.push(`content-type ${ct}`);
      try {
        const data = JSON.parse(body);
        if (JSON.stringify(data) !== JSON.stringify({ message: 'Hello, World!' })) {
          errors.push(`json mismatch: ${body.slice(0, 60)}`);
        }
      } catch {
        errors.push(`invalid json: ${body.slice(0, 60)}`);
      }
      break;
    case 'db':
      if (!ct.startsWith('application/json')) errors.push(`content-type ${ct}`);
      try {
        const d = JSON.parse(body);
        if (!Number.isInteger(d.id) || d.id < 1 || d.id > 10000
          || !Number.isInteger(d.randomNumber) || Object.keys(d).length !== 2) {
          errors.push(`db row shape: ${body.slice(0, 80)}`);
        }
      } catch {
        errors.push(`invalid json: ${body.slice(0, 60)}`);
      }
      break;
    case 'queries': {
      if (!ct.startsWith('application/json')) errors.push(`content-type ${ct}`);
      try {
        const rows = JSON.parse(body);
        if (!Array.isArray(rows) || rows.length !== 20) errors.push(`expected 20 rows, got ${rows.length}`);
        else if (!rows.every((r) => Number.isInteger(r.id) && Number.isInteger(r.randomNumber) && Object.keys(r).length === 2)) {
          errors.push('row shape mismatch');
        }
      } catch {
        errors.push(`invalid json: ${body.slice(0, 60)}`);
      }
      break;
    }
    case 'fortunes': {
      if (!ct.startsWith('text/html')) errors.push(`content-type ${ct}`);
      // The <script> fortune row must be HTML-escaped in output.
      if (!body.includes('&lt;script&gt;alert(')) errors.push('script row not escaped');
      if (!body.includes('ฉันสามารถกินแก้ว')) errors.push('missing UTF-8 (Thai) row');
      if (!body.includes('Additional fortune added at request time.')) errors.push('missing appended row');
      // Byte-identical template output across all apps (reference = plain-php).
      if (reference !== null && body !== reference) {
        errors.push(`HTML output differs from plain-php reference (${body.length} vs ${reference.length} bytes)`);
      }
      return { ok: errors.length === 0, errors, body };
    }
  }
  return { ok: errors.length === 0, errors, body };
}
