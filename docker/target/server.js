'use strict';

const http = require('http');
const { URL } = require('url');
const { handleFakeAsaas } = require('./fake-asaas');
const { handleFakeTelegram } = require('./fake-telegram');
const { handleFakeProbe } = require('./fake-probe');
const { handleFakeZenvia } = require('./fake-zenvia');
const { startFakeMqtt } = require('./fake-mqtt');

const PORT = Number(process.env.PORT || 8080);
let flapCounter = 0;

function sendResponse(res, statusCode, bodyText, headers = {}) {
  const content = bodyText || '';
  res.writeHead(statusCode, {
    'Content-Type': 'text/plain; charset=utf-8',
    'Content-Length': Buffer.byteLength(content),
    ...headers,
  });
  res.end(content);
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url || '/', `http://${req.headers.host || 'localhost'}`);
  const pathname = url.pathname;

  // Fake Asaas billing API for the browser tests (fake-asaas.js).
  if (await handleFakeAsaas(req, res, url)) {
    return;
  }

  // Fake Telegram Bot API for the browser tests (fake-telegram.js).
  if (await handleFakeTelegram(req, res, url)) {
    return;
  }

  // Fake Zenvia WhatsApp API for the browser tests (fake-zenvia.js, #215).
  if (await handleFakeZenvia(req, res, url)) {
    return;
  }

  // Fake remote probe for the batch-execution tests (fake-probe.js, #222).
  if (await handleFakeProbe(req, res, url)) {
    return;
  }

  // Immediate socket destruction to simulate transport errors
  if (pathname === '/close') {
    req.socket.destroy();
    return;
  }

  // Chained redirects: /redirect/3 -> /redirect/2 -> /redirect/1 -> /status/200
  const redirectMatch = pathname.match(/^\/redirect\/(\d+)$/);
  if (redirectMatch) {
    const remaining = parseInt(redirectMatch[1], 10);
    if (remaining > 1) {
      sendResponse(res, 302, `Redirecting to /redirect/${remaining - 1}`, {
        Location: `/redirect/${remaining - 1}`,
      });
      return;
    } else {
      sendResponse(res, 302, 'Redirecting to /status/200', {
        Location: '/status/200',
      });
      return;
    }
  }

  // Status code control: /status/{code} or /status?code={code}
  const statusMatch = pathname.match(/^\/status\/(\d+)$/);
  if (statusMatch) {
    const code = parseInt(statusMatch[1], 10);
    sendResponse(res, code, `Status ${code}`);
    return;
  }

  // Delay response: /delay/{ms} or /delay?ms=1000
  const delayMatch = pathname.match(/^\/delay\/(\d+)$/);
  const delayMs = delayMatch
    ? parseInt(delayMatch[1], 10)
    : (url.searchParams.has('ms') ? parseInt(url.searchParams.get('ms'), 10) : null);

  if (pathname.startsWith('/delay') && delayMs !== null) {
    await new Promise((resolve) => setTimeout(resolve, delayMs));
    sendResponse(res, 200, `Delayed by ${delayMs}ms`);
    return;
  }

  // Flap deterministic response: /flap?period=N
  if (pathname === '/flap') {
    const period = parseInt(url.searchParams.get('period') || '1', 10);
    flapCounter++;
    const isUp = Math.floor((flapCounter - 1) / period) % 2 === 0;
    if (isUp) {
      sendResponse(res, 200, `Flap UP (count=${flapCounter})`);
    } else {
      sendResponse(res, 500, `Flap DOWN (count=${flapCounter})`);
    }
    return;
  }

  // Custom body text: /body?text=...
  if (pathname === '/body') {
    const text = url.searchParams.get('text') || 'Target server default body';
    sendResponse(res, 200, text);
    return;
  }

  // Default response
  sendResponse(res, 200, 'Target server active OK');
});

// Fake MQTT broker on 1883, 8883 (TLS) and 1884 (silent) for MQTT monitor tests (#200).
startFakeMqtt();

server.listen(PORT, '0.0.0.0', () => {
  console.log(`Target service listening on http://0.0.0.0:${PORT}`);
});
