'use strict';

/*
 * Fake Asaas API v3 for the browser tests (e2e/, #173).
 *
 * Covers what StatusConnect calls (app/Infrastructure/Billing/AsaasClient.php):
 * customers, subscriptions and a subscription's payments. It also serves an
 * invoice page whose "pay" button does what Asaas does after a payment: it
 * sends a PAYMENT_CONFIRMED webhook to StatusConnect.
 *
 * State lives in memory and is lost when the container restarts.
 *
 *   POST   /asaas/v3/customers
 *   POST   /asaas/v3/subscriptions
 *   GET    /asaas/v3/subscriptions/{id}/payments
 *   DELETE /asaas/v3/subscriptions/{id}
 *   GET    /asaas/i/{paymentId}         invoice page
 *   POST   /asaas/i/{paymentId}/pay     marks it paid and sends the webhook
 *
 * Settings: FAKE_ASAAS_WEBHOOK_URL (e.g. http://app/webhooks/asaas) and
 * FAKE_ASAAS_WEBHOOK_TOKEN (the app's ASAAS_WEBHOOK_TOKEN).
 */

const WEBHOOK_URL = process.env.FAKE_ASAAS_WEBHOOK_URL || '';
const WEBHOOK_TOKEN = process.env.FAKE_ASAAS_WEBHOOK_TOKEN || '';

let seq = 0;
const customers = new Map();
const subscriptions = new Map();
const payments = new Map();

const nextId = (prefix) => `${prefix}_${String(++seq).padStart(6, '0')}`;

function json(res, status, body) {
  const content = JSON.stringify(body);
  res.writeHead(status, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(content) });
  res.end(content);
}

function html(res, status, body) {
  const content = `<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Fake Asaas</title></head><body>${body}</body></html>`;
  res.writeHead(status, { 'Content-Type': 'text/html; charset=utf-8', 'Content-Length': Buffer.byteLength(content) });
  res.end(content);
}

function readJson(req) {
  return new Promise((resolve) => {
    let data = '';
    req.on('data', (chunk) => { data += chunk; });
    req.on('end', () => {
      try { resolve(data ? JSON.parse(data) : {}); } catch { resolve(null); }
    });
  });
}

const escape = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

async function sendWebhook(event, payment) {
  if (!WEBHOOK_URL) {
    return { status: 0, error: 'FAKE_ASAAS_WEBHOOK_URL is not set' };
  }
  try {
    const response = await fetch(WEBHOOK_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'asaas-access-token': WEBHOOK_TOKEN },
      body: JSON.stringify({
        id: nextId('evt'),
        event,
        dateCreated: new Date().toISOString().slice(0, 19).replace('T', ' '),
        payment: { object: 'payment', ...payment },
      }),
    });
    return { status: response.status };
  } catch (e) {
    return { status: 0, error: e.message };
  }
}

/** Handles /asaas/* and returns true; returns false for any other path. */
async function handleFakeAsaas(req, res, url) {
  const path = url.pathname;
  if (!path.startsWith('/asaas/')) {
    return false;
  }

  // Invoice page and payment, opened by the customer's browser.
  const invoice = path.match(/^\/asaas\/i\/([A-Za-z0-9_]+)(\/pay)?$/);
  if (invoice) {
    const payment = payments.get(invoice[1]);
    if (!payment) {
      html(res, 404, '<h1>Cobrança não encontrada</h1>');
      return true;
    }
    if (invoice[2] && req.method === 'POST') {
      payment.status = 'CONFIRMED';
      const delivery = await sendWebhook('PAYMENT_CONFIRMED', payment);
      html(res, 200, `<h1>Pagamento confirmado</h1><p data-webhook-status="${delivery.status}">Webhook: HTTP ${delivery.status}${delivery.error ? ` (${escape(delivery.error)})` : ''}</p>`);
      return true;
    }
    html(res, 200, `<h1>Fatura ${escape(payment.id)}</h1>
<p>${escape(payment.description)}</p>
<p>Valor: R$ <span data-value>${payment.value.toFixed(2)}</span> · Status: <span data-status>${escape(payment.status)}</span></p>
<form method="post" action="/asaas/i/${escape(payment.id)}/pay"><button type="submit">Pagar</button></form>`);
    return true;
  }

  // API calls, made by StatusConnect.
  if (!req.headers.access_token) {
    json(res, 401, { errors: [{ code: 'invalid_access_token', description: 'Missing access_token' }] });
    return true;
  }

  if (path === '/asaas/v3/customers' && req.method === 'POST') {
    const body = await readJson(req);
    if (!body || !body.name || !body.cpfCnpj) {
      json(res, 400, { errors: [{ code: 'invalid_customer', description: 'name and cpfCnpj are required' }] });
      return true;
    }
    const id = nextId('cus');
    customers.set(id, { id, ...body });
    json(res, 200, { object: 'customer', id, name: body.name });
    return true;
  }

  if (path === '/asaas/v3/subscriptions' && req.method === 'POST') {
    const body = await readJson(req);
    if (!body || !customers.has(body.customer)) {
      json(res, 400, { errors: [{ code: 'invalid_customer', description: 'Unknown customer' }] });
      return true;
    }
    const id = nextId('sub');
    subscriptions.set(id, { id, status: 'ACTIVE', ...body });
    const paymentId = nextId('pay');
    payments.set(paymentId, {
      id: paymentId,
      subscription: id,
      customer: body.customer,
      status: 'PENDING',
      value: Number(body.value),
      dueDate: body.nextDueDate,
      description: body.description || '',
      invoiceUrl: `http://${req.headers.host}/asaas/i/${paymentId}`,
    });
    json(res, 200, { object: 'subscription', id, status: 'ACTIVE' });
    return true;
  }

  // NFS-e invoice settings (#216): stored and echoed back.
  const settings = path.match(/^\/asaas\/v3\/subscriptions\/([A-Za-z0-9_]+)\/invoiceSettings$/);
  if (settings && subscriptions.has(settings[1])) {
    const current = subscriptions.get(settings[1]);
    if (req.method === 'POST') {
      current.invoiceSettings = await readJson(req);
    }
    json(res, 200, current.invoiceSettings || {});
    return true;
  }

  const sub = path.match(/^\/asaas\/v3\/subscriptions\/([A-Za-z0-9_]+)(\/payments)?$/);
  if (sub && subscriptions.has(sub[1])) {
    if (sub[2] && req.method === 'GET') {
      json(res, 200, { object: 'list', data: [...payments.values()].filter((p) => p.subscription === sub[1]) });
      return true;
    }
    if (!sub[2] && req.method === 'DELETE') {
      subscriptions.get(sub[1]).status = 'INACTIVE';
      json(res, 200, { deleted: true, id: sub[1] });
      return true;
    }
  }

  json(res, 404, { errors: [{ code: 'not_found', description: `${req.method} ${path}` }] });
  return true;
}

module.exports = { handleFakeAsaas };
