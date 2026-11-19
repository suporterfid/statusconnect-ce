'use strict';

/*
 * Fake Zenvia API for the browser tests (e2e/, #215).
 *
 * The app points ZENVIA_API_URL at http://target:8080/zenvia
 * (app/Infrastructure/WhatsApp/ZenviaClient.php). Messages are kept so a test
 * can read them:
 *
 *   POST   /zenvia/channels/whatsapp/messages   send; a "to" ending in 0000 fails with 400
 *   GET    /zenvia/_messages                     the messages sent so far
 *   GET    /zenvia/subscriptions                 the subscriptions
 *   POST   /zenvia/subscriptions                 create one
 *   DELETE /zenvia/subscriptions/<id>            delete one
 *
 * State lives in memory and is lost when the container restarts.
 */

const messages = [];
let subscriptions = [];

function json(res, status, body) {
  const text = body === null ? '' : JSON.stringify(body);
  res.writeHead(status, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(text) });
  res.end(text);
}

function readBody(req) {
  return new Promise((resolve) => {
    let data = '';
    req.on('data', (chunk) => { data += chunk; });
    req.on('end', () => {
      try {
        resolve(data ? JSON.parse(data) : {});
      } catch (e) {
        resolve({});
      }
    });
  });
}

async function handleFakeZenvia(req, res, url) {
  const path = url.pathname;
  if (!path.startsWith('/zenvia/')) {
    return false;
  }
  if (!req.headers['x-api-token']) {
    if (path !== '/zenvia/_messages') {
      json(res, 401, { code: 'UNAUTHORIZED', message: 'Missing token' });
      return true;
    }
  }

  if (path === '/zenvia/_messages' && req.method === 'GET') {
    json(res, 200, { messages });
    return true;
  }

  if (path === '/zenvia/channels/whatsapp/messages' && req.method === 'POST') {
    const body = await readBody(req);
    if (String(body.to || '').endsWith('0000')) {
      json(res, 400, { code: 'VALIDATION_ERROR', message: 'Invalid recipient', details: [] });
      return true;
    }
    const id = 'fake-' + (messages.length + 1);
    messages.push({ id, from: body.from, to: body.to, contents: body.contents || [] });
    json(res, 200, { id, from: body.from, to: body.to, direction: 'OUT', channel: 'whatsapp', contents: body.contents || [] });
    return true;
  }

  if (path === '/zenvia/subscriptions' && req.method === 'GET') {
    json(res, 200, subscriptions);
    return true;
  }

  if (path === '/zenvia/subscriptions' && req.method === 'POST') {
    const body = await readBody(req);
    const subscription = { id: 'sub-' + (subscriptions.length + 1) + '-' + Date.now(), ...body, status: 'ACTIVE' };
    subscriptions.push(subscription);
    json(res, 200, subscription);
    return true;
  }

  const one = path.match(/^\/zenvia\/subscriptions\/([^/]+)$/);
  if (one && req.method === 'DELETE') {
    subscriptions = subscriptions.filter((s) => s.id !== one[1]);
    json(res, 204, null);
    return true;
  }

  json(res, 404, { code: 'NOT_FOUND', message: 'Not Found' });
  return true;
}

module.exports = { handleFakeZenvia };
