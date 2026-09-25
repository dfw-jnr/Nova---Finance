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

  function txnAmountClass(row) {
    if (row.type === 'transfer') return 'txn__amount--xfer';
    if (row.type === 'income') return 'txn__amount--in';
    return 'txn__amount--out';
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
          const pending = String(t.id).startsWith('pending-');
          const xfer = t.type === 'transfer';
          const meta = xfer
            ? escapeHtml(t.category_name || 'Transfer')
            : `${escapeHtml(t.category_name || t.category || '—')}${pending ? ' · pending sync' : ''}`;
          return `
          <button type="button" class="txn" data-id="${t.id}" ${pending ? 'disabled' : ''}>
            <span>
              <span class="txn__merchant">${escapeHtml(t.merchant)}</span>
              <span class="txn__meta">${meta}</span>
            </span>
            <span class="txn__amount ${txnAmountClass(t)}"></span>
          </button>`;
        }).join('')}
      </section>`).join('');

    container.querySelectorAll('.txn').forEach((btn) => {
      const id = btn.dataset.id;
      const row = rows.find((r) => String(r.id) === String(id));
      if (!row) return;
      const amountEl = btn.querySelector('.txn__amount');
      amountEl.innerHTML = `${NovaUI.money(row.amount, currency, false, row.type)}
        <span class="visually-hidden">${row.type}</span>`;
      amountEl.className = 'txn__amount ' + txnAmountClass(row);
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

  global.NovaTx = { renderList, dayLabel, escapeHtml, txnAmountClass };
})(window);
