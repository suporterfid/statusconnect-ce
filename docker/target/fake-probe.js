'use strict';

// Fake StatusConnect remote probe for the batch-execution tests (#222).
//
// POST /fake-probe            batch: answers every check without fetching it.
//   - a URL with /status/{code} answers that code; /delay/{ms} waits, then 200;
//   - a URL with "blocked" is refused as private; "drop" is left out of the answer.
// POST /fake-probe/fail       always 503 (probe down).
// ?location=Name              answers as that probe location (a second region, #222).
// GET  /fake-probe/last       the last batch received: headers and parsed body.
let last = null;

function json(res, status, body) {
  const text = JSON.stringify(body);
  res.writeHead(status, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(text) });
  res.end(text);
}

function readBody(req) {
  return new Promise((resolve) => {
    let data = '';
    req.on('data', (chunk) => { data += chunk; });
    req.on('end', () => resolve(data));
  });
}

async function handleFakeProbe(req, res, url) {
  if (!url.pathname.startsWith('/fake-probe')) {
    return false;
  }
  if (req.method === 'GET' && url.pathname === '/fake-probe/last') {
    json(res, 200, last || {});
    return true;
  }
  const raw = await readBody(req);
  if (url.pathname === '/fake-probe/fail') {
    json(res, 503, { error: 'unavailable' });
    return true;
  }
  let payload;
  try {
    payload = JSON.parse(raw);
  } catch (e) {
    json(res, 400, { error: 'invalid_request' });
    return true;
  }
  last = { headers: req.headers, body: payload };
  const location = url.searchParams.get('location') || 'Fake Probe';
  const checks = Array.isArray(payload.checks) ? payload.checks : [];
  const results = [];
  let wait = 0;
  for (const check of checks) {
    const target = String(check.url || '');
    if (target.includes('drop')) continue;
    if (target.includes('blocked')) {
      results.push({ id: check.id, blocked: 'private_address' });
      continue;
    }
    const delay = target.match(/\/delay\/(\d+)/);
    if (delay) wait = Math.max(wait, parseInt(delay[1], 10));
    const status = target.match(/\/status\/(\d{3})/);
    const code = status ? parseInt(status[1], 10) : 200;
    results.push({
      id: check.id, v: 1, location, kind: 'http', ok: true, status_code: code,
      latency_ms: delay ? parseInt(delay[1], 10) : 12, headers: { 'content-type': 'text/plain' },
      body_base64: Buffer.from(`Fake ${code}`).toString('base64'), tls_expires_at: null, error: null,
    });
  }
  setTimeout(() => json(res, 200, { v: 1, location, results }), wait);
  return true;
}

module.exports = { handleFakeProbe };
