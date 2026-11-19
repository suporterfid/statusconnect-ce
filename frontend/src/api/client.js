const API_BASE = '';

function readCookie(name) {
  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
  return match ? decodeURIComponent(match[1]) : null;
}

// The operator SPA can be entered on any route, and not every route's
// response reliably sets the XSRF-TOKEN cookie (e.g. the static file
// response for /app/ itself does not). Sanctum's dedicated endpoint
// always sets it, so fetch it once before the first request that needs it.
async function ensureCsrfCookie() {
  if (!readCookie('XSRF-TOKEN')) {
    await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });
  }
}

// A cookie can exist but be stale (expired/rotated session, browser tab
// left open across a deploy) — ensureCsrfCookie() only fetches when no
// cookie is present at all, so a stale one is never refreshed on its own.
// Laravel rejects a stale token with 419, which this forces past by
// always fetching a fresh cookie regardless of what's currently set.
async function refreshCsrfCookie() {
  await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });
}

async function performRequest(endpoint, options) {
  const token = localStorage.getItem('auth_token');
  const tenantId = localStorage.getItem('current_tenant_id');
  const environmentId = localStorage.getItem('current_environment_id');
  const xsrfToken = readCookie('XSRF-TOKEN');

  const headers = {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    ...options.headers,
  };

  // Let the browser set the multipart boundary for file uploads.
  if (options.body instanceof FormData) {
    delete headers['Content-Type'];
  }

  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  if (tenantId) {
    headers['X-Tenant-ID'] = tenantId;
  }
  if (environmentId) {
    headers['X-Environment-ID'] = environmentId;
  }

  // Laravel's 'web' middleware (Sanctum's statefulApi()) verifies this
  // header against the XSRF-TOKEN cookie for session-authenticated
  // requests like login. The cookie is set by any prior page load.
  if (xsrfToken) {
    headers['X-XSRF-TOKEN'] = xsrfToken;
  }

  return fetch(`${API_BASE}${endpoint}`, {
    ...options,
    credentials: 'same-origin',
    headers,
  });
}

export async function apiRequest(endpoint, options = {}) {
  await ensureCsrfCookie();

  let response = await performRequest(endpoint, options);

  if (response.status === 419) {
    await refreshCsrfCookie();
    response = await performRequest(endpoint, options);
  }

  if (response.status === 204) {
    return null;
  }

  const data = await response.json().catch(() => ({}));

  // The terms changed while signed in: a full reload re-reads /v1/me and shows the acceptance page.
  if (response.status === 403 && data.code === 'terms_acceptance_required' && !window.location.pathname.startsWith('/app/terms')) {
    window.location.assign('/app/terms');
  }

  if (!response.ok) {
    const error = new Error(data.message || 'API request failed');
    error.status = response.status;
    error.data = data;
    throw error;
  }

  return data;
}
