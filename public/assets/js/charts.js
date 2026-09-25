(function (global) {
  'use strict';

  function buildPath(values, w, h, pad) {
    if (!values.length) return '';
    const max = Math.max(...values, 1);
    const iw = w - pad.l - pad.r;
    const ih = h - pad.t - pad.b;
    return values.map((v, i) => {
      const x = pad.l + (values.length === 1 ? iw / 2 : (i / (values.length - 1)) * iw);
      const y = pad.t + ih - (v / max) * ih;
      return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)} ${y.toFixed(1)}`;
    }).join(' ');
  }

  function renderCashflow(svg, series, reduceMotion) {
    if (!svg) return;
    const w = 640, h = 200;
    const pad = { t: 12, r: 8, b: 12, l: 8 };
    const income = series.map((s) => Number(s.income) || 0);
    const expenses = series.map((s) => Number(s.expenses) || 0);

    const paint = () => {
      const dIn = buildPath(income.length ? income : [0], w, h, pad);
      const dOut = buildPath(expenses.length ? expenses : [0], w, h, pad);
      svg.innerHTML = `
        <path class="line line-in" d="${dIn}" />
        <path class="line line-out" d="${dOut}" />`;
    };

    if (reduceMotion) {
      paint();
      return;
    }
    svg.classList.add('is-morphing');
    setTimeout(() => {
      paint();
      svg.classList.remove('is-morphing');
    }, 200);
  }

  function renderBars(container, rows, currency) {
    if (!container) return;
    const max = Math.max(...rows.map((r) => Number(r.total) || 0), 1);
    container.innerHTML = rows.map((r, i) => {
      const pct = ((Number(r.total) / max) * 100).toFixed(1);
      return `<div class="bar-row" style="--i:${i}">
        <span class="name">${NovaTx.escapeHtml(r.category)}</span>
        <span class="track"><span class="fill" style="--w:${pct}%; transition-delay:${i * 40}ms"></span></span>
        <span class="amt">${NovaUI.money(r.total, currency)}</span>
      </div>`;
    }).join('') || '<p class="sub">No spending in this period.</p>';
  }

  global.NovaCharts = { renderCashflow, renderBars };
})(window);
