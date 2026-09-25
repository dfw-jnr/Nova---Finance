/**
 * NOVA Finance app shell.
 */
(function () {
  'use strict';

  const state = {
    user: null,
    currency: 'EUR',
    accounts: [],
    categories: [],
    transactions: [],
    filter: { type: '', q: '' },
    range: '30D',
  };

  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];

  function showScreen(name) {
    $$('.screen').forEach((el) => el.classList.toggle('is-active', el.dataset.screen === name));
    $$('.bottom-nav button').forEach((btn) => {
      const on = btn.dataset.nav === name;
      btn.classList.toggle('is-active', on);
      if (on) btn.setAttribute('aria-current', 'page');
      else btn.removeAttribute('aria-current');
    });
    NovaUI.pinChrome?.();
    // Load data after paint so the tab switch feels instant
    requestAnimationFrame(() => {
      if (name === 'home') loadHome();
      if (name === 'transactions') loadTransactions();
      if (name === 'analytics') loadAnalytics();
      if (name === 'goals') loadGoals();
      if (name === 'more') loadMore();
    });
  }

  function paintHome(analytics, txns) {
    state.transactions = txns;
    state.currency = analytics.currency || state.user?.currency || state.currency;
    $('#hero-balance').textContent = NovaUI.money(analytics.total_balance, state.currency);
    $('#stat-income').textContent = NovaUI.money(analytics.month.income, state.currency);
    $('#stat-expenses').textContent = NovaUI.money(analytics.month.expenses, state.currency);
    const savings = Number(analytics.month.income) - Number(analytics.month.expenses);
    $('#stat-savings').textContent = NovaUI.money(Math.max(savings, 0), state.currency);
    NovaTx.renderList($('#home-txns'), txns.slice(0, 6), state.currency, openDetail);
  }

  function readHomeCache() {
    try {
      const raw = sessionStorage.getItem('nova_home_v1');
      if (!raw) return null;
      const data = JSON.parse(raw);
      if (!data?.ts || Date.now() - data.ts > 120000) return null;
      return data;
    } catch {
      return null;
    }
  }

  function writeHomeCache(analytics, txns) {
    try {
      sessionStorage.setItem('nova_home_v1', JSON.stringify({
        ts: Date.now(),
        analytics,
        txns,
      }));
    } catch (_) {}
  }

  let chartsReady = null;
  function ensureCharts() {
    if (window.NovaCharts) return Promise.resolve();
    if (chartsReady) return chartsReady;
    chartsReady = new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = '/assets/js/charts.js?v=13';
      s.async = true;
      s.onload = () => resolve();
      s.onerror = () => reject(new Error('Charts failed to load'));
      document.head.appendChild(s);
    });
    return chartsReady;
  }

  async function loadHome(preloaded) {
    const greet = $('#greet-text');
    if (greet) {
      greet.innerHTML = `${NovaUI.greeting()}<strong>${NovaTx.escapeHtml(state.user?.name?.split(' ')[0] || 'there')}</strong>`;
    }
    const cached = readHomeCache();
    if (!preloaded?.home && cached?.analytics) {
      paintHome(cached.analytics, cached.txns || []);
    }
    try {
      let analytics;
      let txns;
      if (preloaded?.home) {
        analytics = {
          total_balance: preloaded.home.total_balance,
          month: preloaded.home.month,
          currency: preloaded.home.currency || state.currency,
        };
        txns = preloaded.home.recent || [];
      } else {
        [analytics, txns] = await Promise.all([
          NovaAPI.analytics('30D'),
          NovaAPI.transactions('limit=6'),
        ]);
      }
      paintHome(analytics, txns);
      writeHomeCache(analytics, txns);
    } catch (e) {
      if (!cached) NovaUI.toast(e.message || 'Could not load dashboard');
    }
  }

  async function loadTransactions() {
    try {
      const params = new URLSearchParams();
      if (state.filter.type) params.set('type', state.filter.type);
      if (state.filter.q) params.set('q', state.filter.q);
      const rows = await NovaAPI.transactions(params.toString());
      // merge pending offline
      const pending = NovaAPI.getPending().map((p) => ({
        ...p,
        id: 'pending-' + p.client_id,
        category_name: 'Pending',
        txn_date: p.txn_date || p.date,
      }));
      state.transactions = [...pending, ...rows];
      NovaTx.renderList($('#txn-list'), state.transactions, state.currency, openDetail);
    } catch (e) {
      NovaUI.toast(e.message || 'Could not load transactions');
    }
  }

  async function loadAnalytics() {
    const panel = $('#analytics-panel');
    try {
      await ensureCharts();
      const data = await NovaAPI.analytics(state.range);
      state.currency = data.currency;
      $('#an-savings').textContent = data.averages.savings_rate + '%';
      $('#an-avg').textContent = NovaUI.money(data.averages.avg_daily_spend, state.currency);
      $('#an-summary').textContent =
        `Income ${NovaUI.money(data.averages.income, state.currency)} · Expenses ${NovaUI.money(data.averages.expenses, state.currency)} over ${data.averages.days} days.`;
      NovaCharts.renderCashflow($('#cashflow-svg'), data.cashflow, NovaUI.reduce());
      NovaCharts.renderBars($('#category-bars'), data.by_category, state.currency);
      const largest = $('#largest-list');
      largest.innerHTML = (data.largest_expenses || []).map((x) =>
        `<li class="txn" style="cursor:default"><span><span class="txn__merchant">${NovaTx.escapeHtml(x.merchant)}</span>
        <span class="txn__meta">${NovaTx.escapeHtml(x.category_name || '')} · ${x.txn_date}</span></span>
        <span class="txn__amount txn__amount--out">${NovaUI.money(x.amount, state.currency, false, 'expense')}</span></li>`
      ).join('') || '<p class="sub">No expenses yet.</p>';
      panel?.classList.add('is-ready');
      loadBudgets();
    } catch (e) {
      NovaUI.toast(e.message || 'Analytics unavailable');
    }
  }

  async function loadBudgets() {
    const el = $('#budget-list');
    if (!el) return;
    try {
      const rows = await NovaAPI.budgets();
      if (!rows.length) {
        el.innerHTML = `<div class="empty"><h3>No budgets</h3><p>Create a monthly budget to stay on track.</p>
          <button type="button" class="btn btn--ghost" data-open-budget style="width:auto">Create budget</button></div>`;
        el.querySelector('[data-open-budget]')?.addEventListener('click', openBudgetModal);
        return;
      }
      el.innerHTML = rows.map((b) => {
        const spent = Number(b.spent) || 0;
        const limit = Number(b.limit_amount) || 1;
        const pct = Math.min(100, Math.round((spent / limit) * 100));
        const remain = Math.max(0, limit - spent);
        const cls = pct >= 100 ? 'is-over' : pct >= 80 ? 'is-warn' : '';
        return `<article class="card-row is-visible">
          <div class="card-row__top"><span class="card-row__name">${NovaTx.escapeHtml(b.category_name || b.name)}</span>
          <span class="card-row__pct">${pct}%</span></div>
          <div class="progress ${cls}" role="progressbar" aria-valuenow="${pct}" aria-valuemin="0" aria-valuemax="100" aria-label="${NovaTx.escapeHtml(b.category_name)} budget">
            <span style="--p:${pct}%"></span></div>
          <div class="card-row__foot"><span>${NovaUI.money(spent, state.currency)} / ${NovaUI.money(limit, state.currency)}</span>
          <span>${NovaUI.money(remain, state.currency)} left</span></div></article>`;
      }).join('');
    } catch {
      el.innerHTML = '';
    }
  }

  async function loadGoals() {
    const el = $('#goal-list');
    try {
      const rows = await NovaAPI.goals();
      if (!rows.length) {
        el.innerHTML = `<div class="empty"><h3>No goals yet</h3><p>Set a target and track your progress.</p>
          <button type="button" class="btn btn--primary" data-open-goal style="width:auto;display:inline-flex">Create goal</button></div>`;
        el.querySelector('[data-open-goal]')?.addEventListener('click', openGoalModal);
        return;
      }
      el.innerHTML = rows.map((g) => {
        const cur = Number(g.current_amount) || 0;
        const target = Number(g.target_amount) || 1;
        const pct = Math.min(100, Math.round((cur / target) * 100));
        return `<article class="card-row is-visible" style="--accent:${g.accent || 'var(--accent)'}">
          <div class="card-row__top"><span class="card-row__name">${NovaTx.escapeHtml(g.name)}</span>
          <span class="card-row__pct">${pct}%</span></div>
          <div class="progress" role="progressbar" aria-valuenow="${pct}" aria-valuemin="0" aria-valuemax="100">
            <span style="--p:${pct}%;background:${g.accent || 'var(--accent)'}"></span></div>
          <div class="card-row__foot"><span>${NovaUI.money(cur, g.currency)} / ${NovaUI.money(target, g.currency)}</span>
          <button type="button" class="btn btn--ghost" data-contribute="${g.id}" style="min-height:2rem;padding:0 .75rem">Add</button></div>
          ${g.target_date ? `<p class="sub" style="margin-top:.5rem">Target ${g.target_date}</p>` : ''}
        </article>`;
      }).join('');
      el.querySelectorAll('[data-contribute]').forEach((btn) => {
        btn.addEventListener('click', async () => {
          const amount = prompt('Amount to add (€)');
          if (!amount) return;
          try {
            await NovaAPI.contributeGoal(btn.dataset.contribute, amount);
            NovaUI.toast('Goal updated');
            loadGoals();
          } catch (e) {
            NovaUI.toast(e.message);
          }
        });
      });
    } catch (e) {
      NovaUI.toast(e.message);
    }
  }

  async function loadMore() {
    const el = $('#account-list');
    const recEl = $('#recurring-list');
    try {
      const [accounts, recurring] = await Promise.all([NovaAPI.accounts(), NovaAPI.recurring()]);
      state.accounts = accounts;
      el.innerHTML = accounts.map((a) =>
        `<div class="card-row"><div class="card-row__top"><span class="card-row__name">${NovaTx.escapeHtml(a.name)}</span>
        <span class="card-row__pct">${a.type}</span></div>
        <div class="card-row__foot"><span>${NovaUI.money(a.balance, a.currency)}</span><span>${a.currency}</span></div></div>`
      ).join('') || '<p class="empty" style="border:0;padding:1rem 0">No accounts</p>';

      if (recEl) {
        if (!recurring.length) {
          recEl.innerHTML = '<p style="color:var(--text-3);font-size:.875rem;padding:.5rem 0">No recurring items yet</p>';
        } else {
          recEl.innerHTML = recurring.map((r) =>
            `<div class="card-row" data-recurring-id="${r.id}">
              <div class="card-row__top"><span class="card-row__name">${NovaTx.escapeHtml(r.name)}</span>
              <span class="card-row__pct">${r.frequency}</span></div>
              <div class="card-row__foot">
                <span>${NovaUI.money(r.amount, r.currency || state.currency, false, r.type)} · next ${r.next_date}</span>
                <button type="button" class="chip" data-stop-recurring="${r.id}">Stop</button>
              </div>
            </div>`
          ).join('');
          recEl.querySelectorAll('[data-stop-recurring]').forEach((btn) => {
            btn.addEventListener('click', async () => {
              if (!confirm('Stop this recurring item?')) return;
              try {
                await NovaAPI.deleteRecurring(btn.dataset.stopRecurring);
                NovaUI.toast('Stopped');
                loadMore();
              } catch (e) {
                NovaUI.toast(e.message);
              }
            });
          });
        }
      }
    } catch {
      el.innerHTML = '';
      if (recEl) recEl.innerHTML = '';
    }
  }

  async function openDetail(row) {
    const modal = $('#modal-detail');
    $('#detail-amount').textContent = NovaUI.money(row.amount, row.currency || state.currency, false, row.type);
    $('#detail-amount').className = 'detail-amount ' + (row.type === 'income' ? 'txn__amount--in' : '');
    $('#detail-merchant').textContent = row.merchant;
    $('#detail-category').textContent = row.category_name || '—';
    $('#detail-date').textContent = row.txn_date;
    $('#detail-account').textContent = row.account_name || '—';
    $('#detail-notes').textContent = row.notes || row.description || '—';
    $('#detail-created').textContent = row.created_at || '—';
    modal.dataset.id = row.id;
    modal._row = row;
    const wrap = $('#detail-receipt-wrap');
    const img = $('#detail-receipt');
    if (wrap && img) {
      if (row.has_receipt) {
        wrap.hidden = false;
        img.src = '/api/receipts/?transaction_id=' + encodeURIComponent(row.id) + '&t=' + Date.now();
      } else {
        wrap.hidden = true;
        img.removeAttribute('src');
      }
    }
    NovaUI.openModal('modal-detail');
  }

  async function populateSelects(force = false) {
    if (!force && state.accounts.length && state.categories.length) {
      fillSelects(state.accounts, state.categories);
      return;
    }
    const [accounts, categories] = await Promise.all([NovaAPI.accounts(), NovaAPI.categories()]);
    state.accounts = accounts;
    state.categories = categories;
    fillSelects(accounts, categories);
  }

  function fillSelects(accounts, categories) {
    const accSel = $('#txn-account');
    const catSel = $('#txn-category');
    const budCat = $('#budget-category');
    const recAcc = $('#rec-account');
    const recCat = $('#rec-category');
    if (accSel) {
      accSel.innerHTML = accounts.map((a) => `<option value="${a.id}">${NovaTx.escapeHtml(a.name)}</option>`).join('');
    }
    const catOpts = categories.map((c) => `<option value="${c.id}">${NovaTx.escapeHtml(c.name)}</option>`).join('');
    if (catSel) catSel.innerHTML = '<option value="">Select</option>' + catOpts;
    if (budCat) budCat.innerHTML = catOpts;
    if (recAcc) recAcc.innerHTML = accounts.map((a) => `<option value="${a.id}">${NovaTx.escapeHtml(a.name)}</option>`).join('');
    if (recCat) recCat.innerHTML = '<option value="">Select</option>' + catOpts;
  }

  function resetTxnForm() {
    const form = $('#form-txn');
    form?.reset();
    $('#txn-edit-id').value = '';
    $('#txn-title').textContent = 'Add Transaction';
    $('#txn-date').value = new Date().toISOString().slice(0, 10);
    NovaReceipt?.clearPending();
  }

  function resetGoalForm() {
    const form = $('#form-goal');
    form?.reset();
    if ($('#goal-current')) $('#goal-current').value = '0';
  }

  function resetBudgetForm() {
    $('#form-budget')?.reset();
  }

  function resetAccountForm() {
    const form = $('#form-account');
    form?.reset();
    if ($('#acc-type')) $('#acc-type').value = 'bank';
    if ($('#acc-currency')) $('#acc-currency').value = state.currency || 'EUR';
    if ($('#acc-balance')) $('#acc-balance').value = '0';
  }

  function openGoalModal() {
    resetGoalForm();
    NovaUI.openModal('modal-goal');
  }

  function openBudgetModal() {
    populateSelects();
    resetBudgetForm();
    NovaUI.openModal('modal-budget');
  }

  function openAccountModal() {
    resetAccountForm();
    NovaUI.openModal('modal-account');
  }

  function bindForms() {
    $('#form-txn')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const editId = fd.get('id');
      const payload = {
        type: fd.get('type'),
        amount: fd.get('amount'),
        merchant: fd.get('merchant'),
        category_id: fd.get('category_id'),
        account_id: fd.get('account_id'),
        txn_date: fd.get('txn_date'),
        description: fd.get('description'),
        notes: fd.get('notes'),
      };
      $$('#form-txn .field').forEach((f) => f.classList.remove('is-invalid'));
      let ok = true;
      if (!payload.merchant) { $('#field-merchant').classList.add('is-invalid'); ok = false; }
      if (!payload.amount || Number(String(payload.amount).replace(',', '.')) <= 0) {
        $('#field-amount').classList.add('is-invalid'); ok = false;
      }
      if (!ok) return;
      try {
        const receipt = NovaReceipt?.getPending?.();
        if (receipt?.base64) {
          payload.receipt_base64 = receipt.base64;
          payload.receipt_ocr = receipt.ocr || '';
        }
        if (editId) {
          await NovaAPI.updateTransaction(editId, payload);
          NovaUI.toast('Transaction updated');
        } else {
          const saved = await NovaAPI.createTransaction(payload);
          NovaUI.toast(saved.pending ? 'Saved offline — will sync' : 'Transaction saved');
          if (!navigator.onLine) NovaUI.setStatus('offline');
        }
        NovaUI.closeModal('modal-txn');
        resetTxnForm();
        showScreen($('.bottom-nav button.is-active')?.dataset.nav || 'home');
      } catch (err) {
        NovaUI.toast(err.message);
      }
    });

    $('#form-budget')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      try {
        await NovaAPI.createBudget({
          name: fd.get('name'),
          category_id: fd.get('category_id'),
          limit_amount: fd.get('limit_amount'),
        });
        NovaUI.toast('Budget created');
        NovaUI.closeModal('modal-budget');
        resetBudgetForm();
        loadAnalytics();
      } catch (err) {
        NovaUI.toast(err.message);
      }
    });

    $('#form-goal')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      try {
        await NovaAPI.createGoal({
          name: fd.get('name'),
          target_amount: fd.get('target_amount'),
          current_amount: fd.get('current_amount') || '0',
          target_date: fd.get('target_date') || null,
        });
        NovaUI.toast('Goal created');
        NovaUI.closeModal('modal-goal');
        resetGoalForm();
        loadGoals();
      } catch (err) {
        NovaUI.toast(err.message);
      }
    });

    $('#form-account')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      try {
        await NovaAPI.createAccount({
          name: fd.get('name'),
          type: fd.get('type'),
          currency: fd.get('currency') || state.currency,
          balance: fd.get('balance') || '0',
        });
        NovaUI.toast('Account created');
        NovaUI.closeModal('modal-account');
        resetAccountForm();
        loadMore();
        populateSelects(true);
      } catch (err) {
        NovaUI.toast(err.message);
      }
    });

    $('#form-password')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      try {
        await NovaAPI.changePassword({
          current_password: fd.get('current_password'),
          new_password: fd.get('new_password'),
        });
        NovaUI.toast('Password updated');
        NovaUI.closeModal('modal-password');
        e.target.reset();
      } catch (err) {
        NovaUI.toast(err.message);
      }
    });

    $('#form-recurring')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      try {
        await NovaAPI.createRecurring({
          name: fd.get('name'),
          amount: fd.get('amount'),
          type: fd.get('type'),
          frequency: fd.get('frequency'),
          next_date: fd.get('next_date'),
          category_id: fd.get('category_id'),
          account_id: fd.get('account_id'),
        });
        NovaUI.toast('Recurring created');
        NovaUI.closeModal('modal-recurring');
        e.target.reset();
        loadMore();
      } catch (err) {
        NovaUI.toast(err.message);
      }
    });

    $('#btn-delete-txn')?.addEventListener('click', async () => {
      const id = $('#modal-detail').dataset.id;
      if (!id || String(id).startsWith('pending-')) return;
      if (!confirm('Delete this transaction? This cannot be undone.')) return;
      try {
        await NovaAPI.deleteTransaction(id);
        NovaUI.toast('Deleted');
        NovaUI.closeModal('modal-detail');
        loadTransactions();
        loadHome();
      } catch (err) {
        NovaUI.toast(err.message);
      }
    });

    $('#btn-edit-txn')?.addEventListener('click', async () => {
      const modal = $('#modal-detail');
      const row = modal._row;
      if (!row || String(row.id).startsWith('pending-')) return;
      await populateSelects();
      $('#txn-edit-id').value = row.id;
      $('#txn-title').textContent = 'Edit Transaction';
      const typeRadio = document.querySelector(`#form-txn input[name="type"][value="${row.type}"]`);
      if (typeRadio) typeRadio.checked = true;
      $('#txn-amount').value = row.amount;
      $('#txn-merchant').value = row.merchant;
      $('#txn-category').value = row.category_id || '';
      $('#txn-account').value = row.account_id;
      $('#txn-date').value = row.txn_date;
      $('#txn-notes').value = row.notes || '';
      NovaUI.closeModal('modal-detail');
      NovaUI.openModal('modal-txn');
    });

    $('#btn-export-csv')?.addEventListener('click', () => {
      window.location.href = '/api/export/';
    });
  }

  function bindNav() {
    $$('.bottom-nav button').forEach((btn) => {
      // pointerdown feels instant on phones (no click delay)
      const go = (e) => {
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        e.preventDefault();
        showScreen(btn.dataset.nav);
      };
      btn.addEventListener('pointerdown', go);
    });
    $$('[data-open-add], .btn--fab').forEach((el) => {
      el.addEventListener('click', () => {
        resetTxnForm();
        NovaUI.openModal('modal-txn');
        populateSelects();
      });
    });
    $$('[data-open-recurring-btn]').forEach((el) => {
      el.addEventListener('click', () => {
        $('#rec-next').value = new Date().toISOString().slice(0, 10);
        NovaUI.openModal('modal-recurring');
        populateSelects();
      });
    });
    $$('[data-open-password-btn]').forEach((el) => {
      el.addEventListener('click', () => NovaUI.openModal('modal-password'));
    });
    $$('[data-modal-dismiss]').forEach((el) => {
      el.addEventListener('click', () => {
        const modal = el.closest('.modal');
        if (modal) NovaUI.closeModal(modal.id);
      });
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        $$('.modal.is-open').forEach((m) => NovaUI.closeModal(m.id));
      }
    });

    let searchTimer;
    $('#txn-search')?.addEventListener('input', (e) => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => {
        state.filter.q = e.target.value.trim();
        loadTransactions();
      }, 160);
    });
    $$('[data-filter-type]').forEach((chip) => {
      chip.addEventListener('click', () => {
        $$('[data-filter-type]').forEach((c) => c.classList.remove('is-active'));
        chip.classList.add('is-active');
        state.filter.type = chip.dataset.filterType;
        loadTransactions();
      });
    });
    $$('[data-range]').forEach((btn) => {
      btn.addEventListener('click', () => {
        $$('[data-range]').forEach((b) => b.classList.remove('is-active'));
        btn.classList.add('is-active');
        state.range = btn.dataset.range;
        loadAnalytics();
      });
    });

    $('#btn-logout')?.addEventListener('click', async () => {
      try {
        await NovaAPI.logout();
      } catch (_) {}
      location.href = '/login.php';
    });

    $('#theme-cycle')?.addEventListener('click', async () => {
      const order = ['system', 'light', 'dark'];
      const cur = localStorage.getItem('nova-theme') || 'system';
      const next = order[(order.indexOf(cur) + 1) % order.length];
      NovaUI.applyTheme(next);
      try { await NovaAPI.saveTheme(next); } catch (_) {}
      NovaUI.toast('Theme: ' + next);
    });
  }

  NovaUI.initPalette((cmd) => {
    if (cmd === 'add') { resetTxnForm(); NovaUI.openModal('modal-txn'); populateSelects(); }
    if (cmd === 'search') { showScreen('transactions'); $('#txn-search')?.focus(); }
    if (cmd === 'analytics') showScreen('analytics');
    if (cmd === 'budget') openBudgetModal();
    if (cmd === 'goal') openGoalModal();
    if (cmd === 'theme') $('#theme-cycle')?.click();
    if (cmd === 'accounts') showScreen('more');
    if (cmd === 'settings') showScreen('more');
  });

  async function boot() {
    NovaUI.initTheme();
    NovaUI.initChromePin?.();
    try {
      const auth = await NovaAPI.bootstrapAuth();
      if (!auth.authenticated) {
        location.href = '/login.php';
        return;
      }
      state.user = auth.user;
      state.currency = auth.user.currency || 'EUR';
      if (auth.accounts) state.accounts = auth.accounts;
      if (auth.categories) state.categories = auth.categories;
      if (auth.recurring_posted > 0) {
        NovaUI.toast(`${auth.recurring_posted} recurring payment${auth.recurring_posted > 1 ? 's' : ''} posted`);
      }
      bindNav();
      bindForms();
      NovaReceipt?.bind?.();
      if (state.accounts.length && state.categories.length) {
        fillSelects(state.accounts, state.categories);
      }
      // Paint home from the same boot payload — no extra round trips.
      $$('.screen').forEach((el) => el.classList.toggle('is-active', el.dataset.screen === 'home'));
      $$('.bottom-nav button').forEach((btn) => btn.classList.toggle('is-active', btn.dataset.nav === 'home'));
      await loadHome(auth);
      NovaUI.pinChrome?.();

      const sync = () => NovaAPI.syncPending(NovaUI.setStatus).then((r) => {
        if (r.synced) {
          loadHome();
          loadTransactions();
        }
      });
      window.addEventListener('online', () => { NovaUI.setStatus('online'); sync(); });
      window.addEventListener('offline', () => NovaUI.setStatus('offline'));
      if (!navigator.onLine) NovaUI.setStatus('offline');
      else sync();

      if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/service-worker.js').catch(() => {});
      }

      // Keep Render free instance warm while the app is open
      const ping = () => {
        if (document.visibilityState !== 'visible') return;
        fetch('/api/health', { cache: 'no-store', credentials: 'omit' }).catch(() => {});
      };
      setInterval(ping, 7 * 60 * 1000);
      document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
          ping();
          NovaUI.pinChrome?.();
        }
      });

      // Warm charts in the background after first paint
      if ('requestIdleCallback' in window) {
        requestIdleCallback(() => { ensureCharts().catch(() => {}); }, { timeout: 4000 });
      } else {
        setTimeout(() => { ensureCharts().catch(() => {}); }, 2500);
      }
    } catch (e) {
      console.error(e);
      NovaUI.toast('Unable to start app');
    }
  }

  boot();
})();
