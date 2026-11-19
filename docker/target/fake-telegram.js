'use strict';

/*
 * Fake Telegram Bot API for the browser tests (e2e/, #191).
 *
 * The app points TELEGRAM_API_BASE_URL at http://target:8080/telegram and calls
 * /telegram/bot<token>/<method> (app/Infrastructure/Telegram/TelegramBotClient.php).
 * Every method answers ok. sendMessage calls are kept so a test can read them:
 *
 *   POST /telegram/bot<token>/<method>   Bot API call, always {"ok": true}
 *   GET  /telegram/_messages             the sendMessage calls so far
 *
 * State lives in memory and is lost when the container restarts.
 */

const messages = [];

function json(res, status, body) {
  const text = JSON.stringify(body);
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

async function handleFakeTelegram(req, res, url) {
  const path = url.pathname;
  if (!path.startsWith('/telegram/')) {
    return false;
  }

  if (path === '/telegram/_messages' && req.method === 'GET') {
    json(res, 200, { messages });
    return true;
  }

  const call = path.match(/^\/telegram\/bot[^/]+\/([A-Za-z]+)$/);
  if (!call || req.method !== 'POST') {
    json(res, 404, { ok: false, error_code: 404, description: 'Not Found' });
    return true;
  }

  const params = await readBody(req);
  if (call[1] === 'sendMessage') {
    messages.push({ chat_id: params.chat_id, text: params.text });
    json(res, 200, { ok: true, result: { message_id: messages.length, chat: { id: params.chat_id }, text: params.text } });
    return true;
  }

  json(res, 200, { ok: true, result: true });
  return true;
}

module.exports = { handleFakeTelegram };
