/**
 * API client with CSRF + offline queue support.
 */
(function (global) {
  'use strict';

  let csrf = '';

  const pendingKey = 'nova_pending_txns';

  function getPending() {
    try {
      return JSON.parse(localStorage.getItem(pendingKey) || '[]');
    } catch {
      return [];
    }
  }

  function setPending(list) {
    localStorage.setItem(pendingKey, JSON.stringify(list));
  }

  async function request(url, options = {}) {
    const opts = { credentials: 'same-origin', ...options };
    opts.headers = {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrf,
      ...(options.headers || {}),
    };
    if (opts.body && typeof opts.body === 'object') {
      opts.body = JSON.stringify({ ...opts.body, _csrf: csrf });
    }
    const res = await fetch(url, opts);
    let json;
    try {
      json = await res.json();
    } catch {
      throw new Error('Invalid server response');
    }
    if (json && json.data && json.data.csrf) csrf = json.data.csrf;
    if (json && json.csrf) csrf = json.csrf;
    if (!res.ok || json.success === false) {
      const err = new Error(json?.error?.message || 'Request failed');
      err.code = json?.error?.code || 'ERROR';
      err.status = res.status;
      throw err;
    }
    return json.data;
  }

  async function bootstrapAuth() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta?.content) csrf = meta.content;
    const data = await request('/api/boot/');
    if (data.csrf) csrf = data.csrf;
    return data;
  }

  function uuid() {
    if (crypto.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
      const r = (Math.random() * 16) | 0;
      const v = c === 'x' ? r : (r & 0x3) | 0x8;
      return v.toString(16);
    });
  }

  async function createTransaction(payload) {
    const clientId = payload.client_id || uuid();
    const body = { ...payload, client_id: clientId };

    if (!navigator.onLine) {
      if (body.receipt_base64) {
        delete body.receipt_base64;
        delete body.receipt_ocr;
      }
      const pending = getPending();
      pending.push({ ...body, _queued_at: Date.now() });
      setPending(pending);
      return { ...body, id: 'pending-' + clientId, pending: true };
    }

    try {
      return await request('/api/transactions/', { method: 'POST', body });
    } catch (e) {
      if (!navigator.onLine || e.status === 0) {
        if (body.receipt_base64) {
          delete body.receipt_base64;
          delete body.receipt_ocr;
        }
        const pending = getPending();
        pending.push({ ...body, _queued_at: Date.now() });
        setPending(pending);
        return { ...body, id: 'pending-' + clientId, pending: true };
      }
      throw e;
    }
  }

  async function syncPending(onStatus) {
    const pending = getPending();
    if (!pending.length || !navigator.onLine) return { synced: 0 };
    onStatus?.('syncing');
    try {
      const data = await request('/api/sync/', {
        method: 'POST',
        body: { transactions: pending },
      });
      const okIds = new Set(
        (data.results || []).filter((r) => r.success).map((r) => r.client_id)
      );
      const remaining = pending.filter((p) => !okIds.has(p.client_id));
      setPending(remaining);
      onStatus?.(remaining.length ? 'offline' : 'synced');
      return { synced: okIds.size, remaining: remaining.length };
    } catch {
      onStatus?.('offline');
      return { synced: 0 };
    }
  }

  global.NovaAPI = {
    get csrf() { return csrf; },
    setCsrf(v) { csrf = v; },
    request,
    bootstrapAuth,
    uuid,
    createTransaction,
    syncPending,
    getPending,
    login: (body) => request('/api/auth/?action=login', { method: 'POST', body }),
    register: (body) => request('/api/auth/?action=register', { method: 'POST', body }),
    logout: () => request('/api/auth/?action=logout', { method: 'POST', body: {} }),
    transactions: (q = '') => request('/api/transactions/' + (q ? '?' + q : '')),
    transaction: (id) => request('/api/transactions/?id=' + id),
    updateTransaction: (id, body) => request('/api/transactions/?id=' + id, { method: 'PUT', body }),
    deleteTransaction: (id) => request('/api/transactions/?id=' + id, { method: 'DELETE', body: {} }),
    accounts: () => request('/api/accounts/'),
    createAccount: (body) => request('/api/accounts/', { method: 'POST', body }),
    categories: () => request('/api/categories/'),
    analytics: (range = '30D') => request('/api/analytics/?range=' + range),
    budgets: () => request('/api/budgets/'),
    createBudget: (body) => request('/api/budgets/', { method: 'POST', body }),
    goals: () => request('/api/goals/'),
    createGoal: (body) => request('/api/goals/', { method: 'POST', body }),
    contributeGoal: (id, amount) =>
      request('/api/goals/?id=' + id, { method: 'POST', body: { action: 'contribute', amount } }),
    changePassword: (body) => request('/api/auth/?action=change_password', { method: 'POST', body }),
    recurring: () => request('/api/recurring/'),
    createRecurring: (body) => request('/api/recurring/', { method: 'POST', body }),
    deleteRecurring: (id) => request('/api/recurring/?id=' + id, { method: 'DELETE', body: {} }),
    settings: () => request('/api/settings/'),
    saveTheme: (theme) => request('/api/settings/', { method: 'POST', body: { theme } }),
    authCheck: () => request('/api/auth/'),
  };
})(window);
