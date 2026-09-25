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
    forecastHorizon: 30,
    calYear: new Date().getFullYear(),
    calMonth: new Date().getMonth() + 1,
    homeSts: null,
    homeUpcoming: [],
    firstInsight: null,
    importSession: null,
  };

  let lastScreen = 'home';
  let suppressHistory = false;

  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];

  function parseHashScreen() {
    const m = location.hash.match(/^#\/([a-z-]+)/);
    const raw = m ? m[1] : 'home';
    const aliases = {
      budgets: 'analytics',
      accounts: 'more',
      settings: 'more',
      privacy: 'more',
      import: 'more',
    };
    const name = aliases[raw] || raw;
    return ['home', 'transactions', 'analytics', 'goals', 'more', 'calendar'].includes(name) ? name : 'home';
  }

  function showScreen(name, opts = {}) {
    const push = opts.push === true;
    const skipLoad = opts.skipLoad === true;
    if (name === lastScreen && !push && !opts.force) {
      return;
    }
    $$('.screen').forEach((el) => el.classList.toggle('is-active', el.dataset.screen === name));
    $$('.bottom-nav button').forEach((btn) => {
      const on = btn.dataset.nav === name;
      btn.classList.toggle('is-active', on);
      if (on) btn.setAttribute('aria-current', 'page');
      else btn.removeAttribute('aria-current');
    });
    if (push && !suppressHistory) {
      const hash = '#/' + name;
      if (location.hash !== hash) {
        history.pushState({ screen: name }, '', hash);
      }
    }
    lastScreen = name;
    NovaUI.pinChrome?.();
    if (skipLoad) return;
    requestAnimationFrame(() => {
      if (name === 'home') loadHome();
      if (name === 'transactions') loadTransactions();
      if (name === 'analytics') loadAnalytics();
      if (name === 'goals') loadGoals();
      if (name === 'more') loadMore();
      if (name === 'calendar') loadCalendar();
    });
  }

  function paintHome(analytics, txns) {
    state.transactions = txns;
    state.currency = analytics.currency || state.user?.currency || state.currency;
    $('#hero-balance').textContent = NovaUI.money(analytics.total_balance, state.currency);
    const income = analytics.month?.income ?? analytics.saved_income ?? '0';
    const expenses = analytics.month?.expenses ?? '0';
    $('#stat-income').textContent = NovaUI.money(income, state.currency);
    $('#stat-expenses').textContent = NovaUI.money(expenses, state.currency);
    const saved = analytics.saved != null ? analytics.saved : null;
    if (saved != null) {
      $('#stat-savings').textContent = NovaUI.money(saved, state.currency);
    } else {
      // Display-only estimate from month totals (server Decimal preferred via boot.saved)
      const a = Number(income);
      const b = Number(expenses);
      const s = Number.isFinite(a) && Number.isFinite(b) ? Math.max(a - b, 0) : 0;
      $('#stat-savings').textContent = NovaUI.money(s, state.currency);
    }
    const homeTxns = $('#home-txns');
    if (!txns || !txns.length) {
      if (homeTxns) {
        homeTxns.innerHTML = '<p class="sub empty-inline">No recent transactions yet.</p>';
      }
    } else {
      NovaTx.renderList(homeTxns, txns.slice(0, 6), state.currency, openDetail);
    }
    paintSafeToSpend(state.homeSts);
    paintUpcoming(state.homeUpcoming);
    paintHomeInsightTeaser(state.firstInsight);
  }

  function paintSafeToSpend(sts) {
    const valEl = $('#sts-value');
    if (!valEl) return;
    if (!sts) {
      valEl.textContent = NovaUI.money(0, state.currency);
      $('#sts-sub').textContent = 'Tap for breakdown';
      return;
    }
    valEl.textContent = NovaUI.money(sts.safe_to_spend, sts.currency || state.currency);
    const horizon = sts.horizon ? `Through ${sts.horizon}` : 'Tap for breakdown';
    $('#sts-sub').textContent = horizon;
  }

  function openStsModal() {
    const sts = state.homeSts;
    if (!sts) {
      NovaUI.toast('Safe-to-Spend is still loading');
      return;
    }
    const cur = sts.currency || state.currency;
    $('#sts-modal-value').textContent = NovaUI.money(sts.safe_to_spend, cur);
    $('#sts-modal-horizon').textContent = sts.horizon
      ? `Horizon through ${sts.horizon}`
      : 'Based on available balances and reservations';
    const lines = $('#sts-lines');
    const items = sts.lines || [];
    const noteHtml = (sts.notes || []).map((n) =>
      `<li class="sts-note"><span>${NovaTx.escapeHtml(n)}</span><span></span></li>`
    ).join('');
    const otherHtml = (sts.other_currencies || []).map((o) =>
      `<li class="sts-other"><span>${NovaTx.escapeHtml(o.account)} (${NovaTx.escapeHtml(o.currency)})</span><span>${NovaUI.money(o.balance, o.currency)}</span></li>`
    ).join('');
    lines.innerHTML = (items.length
      ? items.map((ln) => {
          const debit = ln.sign === 'debit' || String(ln.amount).startsWith('-');
          const raw = String(ln.amount).replace(/^-/, '');
          const amt = NovaUI.money(raw, cur);
          const detail = ln.detail ? ` <span class="sub">${NovaTx.escapeHtml(ln.detail)}</span>` : '';
          return `<li class="${debit ? 'is-debit' : 'is-credit'}"><span>${NovaTx.escapeHtml(ln.label)}${detail}</span><span>${debit ? '−' : '+'}${amt}</span></li>`;
        }).join('')
      : '<li><span>No breakdown lines yet</span><span>—</span></li>') + otherHtml + noteHtml;
    NovaUI.openModal('modal-sts');
  }

  function paintUpcoming(rows) {
    const el = $('#home-upcoming');
    if (!el) return;
    const list = (rows || []).slice(0, 3);
    if (!list.length) {
      el.innerHTML = '<p class="sub" style="padding:.25rem 0 1rem">Add recurring bills to refine this</p>';
      return;
    }
    el.innerHTML = list.map((r) =>
      `<div class="card-row"><div class="card-row__top"><span class="card-row__name">${NovaTx.escapeHtml(r.name)}</span>
      <span class="card-row__pct">${NovaTx.escapeHtml(r.frequency || '')}</span></div>
      <div class="card-row__foot"><span>${NovaUI.money(r.amount, r.currency || state.currency, false, r.type)}</span>
      <span>${NovaTx.escapeHtml(r.next_date || '')}</span></div></div>`
    ).join('');
  }

  function paintHomeInsightTeaser(insight) {
    const btn = $('#home-insight');
    if (!btn) return;
    if (!insight) {
      btn.hidden = true;
      return;
    }
    btn.hidden = false;
    btn.innerHTML = `<strong>${NovaTx.escapeHtml(insight.title || 'Insight')}</strong>${NovaTx.escapeHtml(insight.summary || insight.body || '')}`;
    btn.onclick = () => openInsightModal(insight);
  }

  function openInsightModal(insight) {
    if (!insight) return;
    $('#insight-title').textContent = insight.title || 'Insight';
    const period = insight.period ? ` · ${insight.period}` : '';
    const body = insight.body || insight.summary || '';
    $('#insight-body').textContent = body + (period && !body.includes(insight.period) ? period : '');
    const periodEl = $('#insight-period');
    if (periodEl) {
      periodEl.textContent = insight.period ? `Period: ${insight.period}` : '';
      periodEl.hidden = !insight.period;
    }
    const list = $('#insight-evidence');
    const ev = insight.evidence || {};
    const metrics = ev.metrics && typeof ev.metrics === 'object' && !Array.isArray(ev.metrics)
      ? Object.entries(ev.metrics).map(([k, v]) => ({ label: k.replace(/_/g, ' '), value: v }))
      : (Array.isArray(ev) ? ev : (Array.isArray(insight.details) ? insight.details : []));
    const txnIds = ev.transaction_ids || insight.transaction_ids || [];
    list.innerHTML = metrics.length
      ? metrics.map((row) => {
          const label = row.label || row.name || row.key || 'Detail';
          const val = row.value ?? row.amount ?? row.text ?? '—';
          return `<li><span>${NovaTx.escapeHtml(String(label))}</span><span>${NovaTx.escapeHtml(String(val))}</span></li>`;
        }).join('')
      : '<li><span>Source: rules engine (no LLM)</span><span>—</span></li>';
    const viewBtn = $('#btn-insight-txns');
    if (viewBtn) {
      viewBtn.hidden = !txnIds.length;
      viewBtn.onclick = () => {
        NovaUI.closeModal('modal-insight');
        showScreen('transactions', { push: true });
      };
    }
    NovaUI.openModal('modal-insight');
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
      s.src = '/assets/js/charts.js?v=16';
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
          saved: preloaded.home.saved,
          currency: preloaded.home.currency || state.currency,
        };
        txns = preloaded.home.recent || [];
        state.homeSts = preloaded.home.safe_to_spend || null;
        state.homeUpcoming = preloaded.home.upcoming || [];
      } else {
        const [an, tx, sts] = await Promise.all([
          NovaAPI.analytics('30D'),
          NovaAPI.transactions('limit=6'),
          NovaAPI.safeToSpend().catch(() => null),
        ]);
        analytics = an;
        txns = tx;
        state.homeSts = sts;
        try {
          state.homeUpcoming = (await NovaAPI.recurring()).filter((r) => (r.is_active ?? 1) === 1).slice(0, 3);
        } catch {
          state.homeUpcoming = [];
        }
      }
      paintHome(analytics, txns);
      refreshHomeInsightTeaser();
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
      loadForecast();
      loadInsights();
    } catch (e) {
      NovaUI.toast(e.message || 'Analytics unavailable');
    }
  }

  async function loadForecast() {
    const el = $('#forecast-balance');
    if (!el) return;
    try {
      const data = await NovaAPI.forecast(state.forecastHorizon);
      state.currency = data.currency || state.currency;
      const label = data.ending_kind === 'projected' ? 'Projected' : 'Balance';
      el.innerHTML = `<span class="forecast-kind">${label}</span> ${NovaUI.money(data.ending_balance, state.currency)}`;
      if (data.may_go_negative) {
        el.classList.add('is-warn');
      } else {
        el.classList.remove('is-warn');
      }
      const disc = $('#forecast-disclaimer');
      if (disc) {
        disc.textContent = data.disclaimer || 'Estimate based on recurring items only.';
      }
    } catch {
      el.textContent = '—';
      el.classList.remove('is-warn');
    }
  }

  async function loadInsights() {
    const el = $('#insights-list');
    if (!el) return;
    try {
      const data = await NovaAPI.insights();
      const rows = Array.isArray(data) ? data : (data.insights || data.items || []);
      if (!rows.length) {
        el.innerHTML = '<p class="sub">Insights will appear as we learn your patterns.</p>';
        state.firstInsight = null;
        paintHomeInsightTeaser(null);
        return;
      }
      state.firstInsight = rows[0];
      paintHomeInsightTeaser(state.firstInsight);
      el.innerHTML = rows.map((ins, i) =>
        `<button type="button" class="insight-card" data-insight-idx="${i}">
          <p class="insight-card__title">${NovaTx.escapeHtml(ins.title || 'Insight')}</p>
          <p class="insight-card__sub">${NovaTx.escapeHtml(ins.summary || ins.body || '')}</p>
        </button>`
      ).join('');
      el._insights = rows;
      el.querySelectorAll('[data-insight-idx]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const idx = Number(btn.dataset.insightIdx);
          openInsightModal(el._insights[idx]);
        });
      });
    } catch {
      el.innerHTML = '<p class="sub">Insights unavailable right now.</p>';
    }
  }

  async function refreshHomeInsightTeaser() {
    if (state.firstInsight) {
      paintHomeInsightTeaser(state.firstInsight);
      return;
    }
    try {
      const data = await NovaAPI.insights();
      const rows = Array.isArray(data) ? data : (data.insights || data.items || []);
      state.firstInsight = rows[0] || null;
      paintHomeInsightTeaser(state.firstInsight);
    } catch {
      paintHomeInsightTeaser(null);
    }
  }

  async function loadCalendar() {
    const grid = $('#cal-grid');
    if (!grid) return;
    const y = state.calYear;
    const m = state.calMonth;
    $('#cal-label').textContent = new Date(y, m - 1, 1).toLocaleDateString('en-IE', { month: 'long', year: 'numeric' });
    grid.innerHTML = '<p class="loading">Loading…</p>';
    try {
      const data = await NovaAPI.calendar(y, m);
      renderCalendarGrid(data);
    } catch (e) {
      grid.innerHTML = `<p class="sub">${NovaTx.escapeHtml(e.message || 'Calendar unavailable')}</p>`;
    }
  }

  function renderCalendarGrid(data) {
    const grid = $('#cal-grid');
    const days = data.days || {};
    const y = data.year || state.calYear;
    const m = data.month || state.calMonth;
    const first = new Date(y, m - 1, 1);
    const startPad = (first.getDay() + 6) % 7;
    const daysInMonth = new Date(y, m, 0).getDate();
    const todayKey = new Date().toISOString().slice(0, 10);
    const dows = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    let html = dows.map((d) => `<div class="cal-dow" role="columnheader">${d}</div>`).join('');
    for (let i = 0; i < startPad; i++) {
      html += '<div class="cal-cell is-outside" aria-hidden="true"></div>';
    }
    for (let d = 1; d <= daysInMonth; d++) {
      const key = `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
      const cell = days[key] || { actual: [], projected: [] };
      const hasA = (cell.actual || []).length > 0;
      const hasP = (cell.projected || []).length > 0;
      html += `<button type="button" class="cal-cell${key === todayKey ? ' is-today' : ''}" data-cal-date="${key}">
        <span>${d}</span>
        <span class="cal-cell__dots">${hasA ? '<span class="cal-dot cal-dot--actual"></span>' : ''}${hasP ? '<span class="cal-dot cal-dot--projected"></span>' : ''}</span>
      </button>`;
    }
    grid.innerHTML = html;
    grid._calDays = days;
    grid.querySelectorAll('[data-cal-date]').forEach((btn) => {
      btn.addEventListener('click', () => openCalDay(btn.dataset.calDate, grid._calDays[btn.dataset.calDate]));
    });
  }

  function openCalDay(dateKey, day) {
    const box = day || { actual: [], projected: [] };
    $('#cal-day-title').textContent = new Date(dateKey + 'T12:00:00').toLocaleDateString('en-IE', {
      weekday: 'long', day: 'numeric', month: 'long',
    });
    const renderRows = (rows, projected) => rows.map((r) => {
      const name = r.merchant || r.name || 'Item';
      const type = r.type || 'expense';
      return `<li class="txn" style="cursor:default;border-top:1px solid var(--border);padding:.75rem 0">
        <span><span class="txn__merchant">${NovaTx.escapeHtml(name)}</span>
        <span class="txn__meta">${projected ? 'Projected' : 'Posted'} · ${NovaTx.escapeHtml(type)}</span></span>
        <span class="txn__amount ${NovaTx.txnAmountClass(r)}">${NovaUI.money(r.amount, r.currency || state.currency, false, type)}</span></li>`;
    }).join('');
    const actual = box.actual || [];
    const projected = box.projected || [];
    $('#cal-day-content').innerHTML =
      (actual.length ? `<h3 class="sub" style="margin:0 0 .5rem">Actual</h3><ul style="list-style:none;padding:0;margin:0 0 1rem">${renderRows(actual, false)}</ul>` : '') +
      (projected.length ? `<h3 class="sub" style="margin:0 0 .5rem">Projected</h3><ul style="list-style:none;padding:0;margin:0">${renderRows(projected, true)}</ul>` : '') ||
      '<p class="sub">Nothing scheduled for this day.</p>';
    NovaUI.openModal('modal-cal-day');
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
    const xfer = row.type === 'transfer';
    $('#detail-amount').textContent = NovaUI.money(row.amount, row.currency || state.currency, false, row.type);
    $('#detail-amount').className = 'detail-amount ' + (row.type === 'income' ? 'txn__amount--in' : xfer ? 'txn__amount--xfer' : '');
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
      if (!xfer && row.has_receipt) {
        wrap.hidden = false;
        img.src = '/api/receipts/?transaction_id=' + encodeURIComponent(row.id) + '&t=' + Date.now();
      } else {
        wrap.hidden = true;
        img.removeAttribute('src');
      }
    }
    const editBtn = $('#btn-edit-txn');
    if (editBtn) {
      editBtn.hidden = xfer;
      editBtn.disabled = xfer;
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
    const fromSel = $('#txn-from');
    const toSel = $('#txn-to');
    const importAcc = $('#import-account');
    const catSel = $('#txn-category');
    const budCat = $('#budget-category');
    const recAcc = $('#rec-account');
    const recCat = $('#rec-category');
    const accOpts = accounts.map((a) => `<option value="${a.id}">${NovaTx.escapeHtml(a.name)}</option>`).join('');
    if (accSel) accSel.innerHTML = accOpts;
    if (fromSel) fromSel.innerHTML = accOpts;
    if (toSel) toSel.innerHTML = accOpts;
    if (importAcc) importAcc.innerHTML = accOpts;
    const catOpts = categories.map((c) => `<option value="${c.id}">${NovaTx.escapeHtml(c.name)}</option>`).join('');
    if (catSel) catSel.innerHTML = '<option value="">Select</option>' + catOpts;
    if (budCat) budCat.innerHTML = catOpts;
    if (recAcc) recAcc.innerHTML = accOpts;
    if (recCat) recCat.innerHTML = '<option value="">Select</option>' + catOpts;
  }

  function txnType() {
    return document.querySelector('#form-txn input[name="type"]:checked')?.value || 'expense';
  }

  function applyTxnTypeUI() {
    const type = txnType();
    const xfer = type === 'transfer';
    $('#field-category')?.toggleAttribute('hidden', xfer);
    $('#field-merchant')?.toggleAttribute('hidden', xfer);
    $('#field-account')?.toggleAttribute('hidden', xfer);
    $('#field-transfer')?.toggleAttribute('hidden', !xfer);
    $('#field-transfer-to')?.toggleAttribute('hidden', !xfer);
    $('#field-receipt')?.toggleAttribute('hidden', xfer);
    const merch = $('#txn-merchant');
    if (merch) {
      if (xfer) merch.removeAttribute('required');
      else merch.setAttribute('required', '');
    }
    const acc = $('#txn-account');
    if (acc) {
      if (xfer) acc.removeAttribute('required');
      else acc.setAttribute('required', '');
    }
  }

  function resetTxnForm() {
    const form = $('#form-txn');
    form?.reset();
    $('#txn-edit-id').value = '';
    $('#txn-title').textContent = 'Add Transaction';
    $('#txn-date').value = new Date().toISOString().slice(0, 10);
    NovaReceipt?.clearPending();
    applyTxnTypeUI();
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
    $$('#form-txn input[name="type"]').forEach((radio) => {
      radio.addEventListener('change', applyTxnTypeUI);
    });

    $('#form-txn')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const editId = fd.get('id');
      const type = fd.get('type');
      $$('#form-txn .field').forEach((f) => f.classList.remove('is-invalid'));
      const amount = fd.get('amount');
      let ok = true;
      if (!amount || Number(String(amount).replace(',', '.')) <= 0) {
        $('#field-amount').classList.add('is-invalid'); ok = false;
      }
      let payload;
      if (type === 'transfer') {
        if (editId) {
          NovaUI.toast('Transfers cannot be edited here');
          return;
        }
        const fromId = fd.get('from_account_id');
        const toId = fd.get('to_account_id');
        if (!fromId || !toId || fromId === toId) {
          $('#field-transfer')?.classList.add('is-invalid');
          $('#field-transfer-to')?.classList.add('is-invalid');
          ok = false;
        }
        payload = {
          type: 'transfer',
          from_account_id: fromId,
          to_account_id: toId,
          amount,
          txn_date: fd.get('txn_date'),
          notes: fd.get('notes'),
        };
      } else {
        payload = {
          type,
          amount,
          merchant: fd.get('merchant'),
          category_id: fd.get('category_id'),
          account_id: fd.get('account_id'),
          txn_date: fd.get('txn_date'),
          description: fd.get('description'),
          notes: fd.get('notes'),
        };
        if (!payload.merchant) { $('#field-merchant').classList.add('is-invalid'); ok = false; }
        if (!payload.account_id) { $('#field-account').classList.add('is-invalid'); ok = false; }
      }
      if (!ok) return;
      try {
        if (type !== 'transfer') {
          const receipt = NovaReceipt?.getPending?.();
          if (receipt?.base64) {
            payload.receipt_base64 = receipt.base64;
            payload.receipt_ocr = receipt.ocr || '';
          }
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
        showScreen(lastScreen || 'home');
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
      if (!row || row.type === 'transfer' || String(row.id).startsWith('pending-')) return;
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
      applyTxnTypeUI();
      NovaUI.closeModal('modal-detail');
      NovaUI.openModal('modal-txn');
    });

    $('#btn-export-csv')?.addEventListener('click', () => {
      window.location.href = NovaAPI.exportUrl('csv');
    });
    $('#btn-export-json')?.addEventListener('click', () => {
      window.location.href = NovaAPI.exportUrl('json');
    });
    $('#btn-logout-all')?.addEventListener('click', async () => {
      if (!confirm('Sign out on all devices?')) return;
      try {
        await NovaAPI.logoutAll();
        NovaUI.toast('Signed out everywhere');
        location.href = '/login.php';
      } catch (e) {
        NovaUI.toast(e.message || 'Could not sign out everywhere');
      }
    });
    $('#btn-delete-account')?.addEventListener('click', async () => {
      if (!confirm('Delete your account permanently? This cannot be undone.')) return;
      const password = prompt('Type your password to confirm deletion');
      if (!password) return;
      try {
        await NovaAPI.deleteAccount(password);
        location.href = '/login.php';
      } catch (e) {
        NovaUI.toast(e.message || 'Could not delete account');
      }
    });

    $('#btn-import-csv')?.addEventListener('click', () => {
      state.importSession = null;
      $('#import-preview').hidden = true;
      $('#import-preview').innerHTML = '';
      const mapBox = $('#import-map');
      if (mapBox) { mapBox.hidden = true; }
      $('#import-file').value = '';
      $('#btn-import-commit').disabled = true;
      populateSelects();
      NovaUI.openModal('modal-import');
    });

    $('#import-file')?.addEventListener('change', async (e) => {
      const file = e.target.files?.[0];
      if (!file) return;
      const fd = new FormData();
      fd.append('file', file);
      const acc = $('#import-account')?.value;
      if (acc) fd.append('account_id', acc);
      try {
        NovaUI.toast('Parsing CSV…');
        const data = await NovaAPI.importCsv(fd);
        state.importSession = data;
        renderImportMap(data);
        renderImportPreview(data);
        $('#btn-import-commit').disabled = false;
      } catch (err) {
        NovaUI.toast(err.message || 'Import preview failed');
      }
    });

    $('#btn-import-remap')?.addEventListener('click', async () => {
      const session = state.importSession;
      const importId = session?.import?.id ?? session?.import_id;
      if (!importId) return;
      const columnMap = {
        date: $('#map-date')?.value || '',
        amount: $('#map-amount')?.value || '',
        description: $('#map-desc')?.value || null,
      };
      if (!columnMap.date || !columnMap.amount) {
        NovaUI.toast('Pick Date and Amount columns');
        return;
      }
      if (!columnMap.description) columnMap.description = null;
      try {
        NovaUI.toast('Re-parsing…');
        const data = await NovaAPI.remapImport(importId, columnMap);
        state.importSession = data;
        renderImportMap(data);
        renderImportPreview(data);
        NovaUI.toast('Mapping applied');
      } catch (err) {
        NovaUI.toast(err.message || 'Could not apply mapping');
      }
    });

    $('#btn-import-commit')?.addEventListener('click', async () => {
      const session = state.importSession;
      const importId = session?.import?.id ?? session?.import_id;
      if (!importId) return;
      const rows = $$('#import-preview [data-import-row]').map((row) => ({
        id: Number(row.dataset.importRow),
        action: row.querySelector('select')?.value || row.dataset.defaultAction || 'import',
      }));
      try {
        await NovaAPI.commitImport(importId, rows);
        NovaUI.toast('Import complete');
        NovaUI.closeModal('modal-import');
        loadTransactions();
        loadHome();
      } catch (e) {
        NovaUI.toast(e.message || 'Import failed');
      }
    });
  }

  function renderImportMap(data) {
    const box = $('#import-map');
    if (!box) return;
    const headers = data.headers || [];
    const map = data.column_map || {};
    if (!headers.length) {
      box.hidden = true;
      return;
    }
    box.hidden = false;
    const opts = headers.map((h) =>
      `<option value="${NovaTx.escapeHtml(h)}">${NovaTx.escapeHtml(h)}</option>`
    ).join('');
    const none = '<option value="">— none —</option>';
    const dateSel = $('#map-date');
    const amtSel = $('#map-amount');
    const descSel = $('#map-desc');
    if (dateSel) {
      dateSel.innerHTML = opts;
      if (map.date) dateSel.value = map.date;
    }
    if (amtSel) {
      amtSel.innerHTML = opts;
      if (map.amount) amtSel.value = map.amount;
    }
    if (descSel) {
      descSel.innerHTML = none + opts;
      descSel.value = map.description || '';
    }
  }

  function renderImportPreview(data) {
    const box = $('#import-preview');
    const rows = data.rows || [];
    if (!rows.length) {
      box.hidden = false;
      box.innerHTML = '<p class="sub">No rows found in file.</p>';
      return;
    }
    box.hidden = false;
    box.innerHTML = rows.map((r) => {
      const dup = r.duplicate_of || r.suggested_action === 'keep';
      const invalid = !r.txn_date || !r.amount;
      const def = r.suggested_action || (dup ? 'keep' : (invalid ? 'skip' : 'import'));
      return `<div class="import-row${dup ? ' is-dup' : ''}${invalid ? ' is-invalid-row' : ''}" data-import-row="${r.id}" data-default-action="${def}">
        <div><strong>${NovaTx.escapeHtml(r.merchant || r.name || 'Row')}</strong>
        <span class="sub">${NovaTx.escapeHtml(r.txn_date || '—')} · ${r.amount != null ? NovaUI.money(r.amount, state.currency, false, r.type || 'expense') : '—'}</span>
        ${dup ? '<span class="sub">Possible duplicate</span>' : ''}
        ${invalid ? '<span class="sub">Needs column mapping</span>' : ''}</div>
        <select aria-label="Import action">
          <option value="import"${def === 'import' ? ' selected' : ''}>Import anyway</option>
          <option value="keep"${def === 'keep' ? ' selected' : ''}>Keep existing</option>
          <option value="skip"${def === 'skip' ? ' selected' : ''}>Skip</option>
        </select>
      </div>`;
    }).join('');
  }

  function bindNav() {
    window.addEventListener('popstate', () => {
      const screen = history.state?.screen || parseHashScreen();
      if (screen === lastScreen) return;
      suppressHistory = true;
      showScreen(screen, { push: false });
      suppressHistory = false;
    });

    $$('.bottom-nav button').forEach((btn) => {
      // pointerdown feels instant on phones (no click delay)
      const go = (e) => {
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        e.preventDefault();
        showScreen(btn.dataset.nav, { push: true });
      };
      btn.addEventListener('pointerdown', go);
    });

    $('#btn-sts')?.addEventListener('click', openStsModal);

    $('#cal-prev')?.addEventListener('click', () => {
      state.calMonth -= 1;
      if (state.calMonth < 1) { state.calMonth = 12; state.calYear -= 1; }
      loadCalendar();
    });
    $('#cal-next')?.addEventListener('click', () => {
      state.calMonth += 1;
      if (state.calMonth > 12) { state.calMonth = 1; state.calYear += 1; }
      loadCalendar();
    });

    $$('[data-forecast]').forEach((btn) => {
      btn.addEventListener('click', () => {
        $$('[data-forecast]').forEach((b) => b.classList.remove('is-active'));
        btn.classList.add('is-active');
        state.forecastHorizon = Number(btn.dataset.forecast) || 30;
        loadForecast();
      });
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
    if (cmd === 'search') { showScreen('transactions', { push: true }); $('#txn-search')?.focus(); }
    if (cmd === 'analytics') showScreen('analytics', { push: true });
    if (cmd === 'budget') openBudgetModal();
    if (cmd === 'goal') openGoalModal();
    if (cmd === 'theme') $('#theme-cycle')?.click();
    if (cmd === 'accounts') showScreen('more', { push: true });
    if (cmd === 'settings') showScreen('more', { push: true });
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
      applyTxnTypeUI();
      if (state.accounts.length && state.categories.length) {
        fillSelects(state.accounts, state.categories);
      }
      const initial = parseHashScreen();
      history.replaceState({ screen: initial }, '', '#/' + initial);
      lastScreen = '';
      showScreen(initial, { push: false, skipLoad: initial === 'home' });
      if (initial === 'home') {
        await loadHome(auth);
      } else if (auth.home) {
        state.homeSts = auth.home.safe_to_spend || null;
        state.homeUpcoming = auth.home.upcoming || [];
      }
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

  window.showScreen = showScreen;
  window.openBudgetModal = openBudgetModal;
  window.openGoalModal = openGoalModal;
  window.openAccountModal = openAccountModal;

  boot();
})();
