(function (global) {
  'use strict';

  function dayLabel(iso) {
    const d = new Date(iso + 'T12:00:00');
    const today = new Date();
    const yday = new Date();
    yday.setDate(today.getDate() - 1);
    const key = (x) => x.toISOString().slice(0, 10);
    if (iso === key(today)) return 'Today';
    if (iso === key(yday)) return 'Yesterday';
    return d.toLocaleDateString('en-IE', { day: 'numeric', month: 'short' });
  }

  function groupByDate(rows) {
    const map = new Map();
    rows.forEach((r) => {
      const k = r.txn_date;
      if (!map.has(k)) map.set(k, []);
      map.get(k).push(r);
    });
    return [...map.entries()];
  }

  function renderList(container, rows, currency, onSelect) {
    if (!container) return;
    if (!rows.length) {
      container.innerHTML = `
        <div class="empty">
          <h3>No transactions yet</h3>
          <p>Add your first transaction to start understanding your spending.</p>
          <button type="button" class="btn btn--primary" data-open-add style="width:auto;margin:0 auto;display:inline-flex;">Add Transaction</button>
        </div>`;
      container.querySelector('[data-open-add]')?.addEventListener('click', () => NovaUI.openModal('modal-txn'));
      return;
    }

    const groups = groupByDate(rows);
    container.innerHTML = groups.map(([date, items]) => `
      <section class="txn-group">
        <h3 class="txn-group__title">${dayLabel(date)}</h3>
        ${items.map((t) => {
          const income = t.type === 'income';
          const pending = String(t.id).startsWith('pending-');
          return `
          <button type="button" class="txn" data-id="${t.id}" ${pending ? 'disabled' : ''}>
            <span>
              <span class="txn__merchant">${escapeHtml(t.merchant)}</span>
              <span class="txn__meta">${escapeHtml(t.category_name || t.category || '—')}${pending ? ' · pending sync' : ''}</span>
            </span>
            <span class="txn__amount ${income ? 'txn__amount--in' : 'txn__amount--out'}">
              <span class="sign" aria-hidden="true">${income ? '+' : '−'}</span>${NovaUI.money(t.amount, currency).replace(/^[€$£]/, '').replace(/^GH₵/, '')}
              <span class="visually-hidden">${income ? 'income' : 'expense'}</span>
            </span>
          </button>`;
        }).join('')}
      </section>`).join('');

    // Fix amount display properly
    container.querySelectorAll('.txn').forEach((btn) => {
      const id = btn.dataset.id;
      const row = rows.find((r) => String(r.id) === String(id));
      if (!row) return;
      const amountEl = btn.querySelector('.txn__amount');
      amountEl.innerHTML = `${NovaUI.money(row.amount, currency, false, row.type)}
        <span class="visually-hidden">${row.type === 'income' ? 'income' : 'expense'}</span>`;
      amountEl.className = 'txn__amount ' + (row.type === 'income' ? 'txn__amount--in' : 'txn__amount--out');
      btn.addEventListener('click', () => onSelect?.(row));
    });
  }

  function escapeHtml(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  global.NovaTx = { renderList, dayLabel, escapeHtml };
})(window);
