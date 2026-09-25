/**
 * UI primitives — theme, toast, modal, palette, status.
 * Motion: Emil Kowalski — no animation on Cmd+K; modal uses scale+opacity.
 */
(function (global) {
  'use strict';

  const reduce = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function toast(message) {
    const region = document.getElementById('toast-region');
    if (!region) return;
    const el = document.createElement('div');
    el.className = 'toast';
    el.setAttribute('role', 'status');
    el.textContent = message;
    region.appendChild(el);
    setTimeout(() => {
      el.setAttribute('data-leaving', '');
      setTimeout(() => el.remove(), reduce() ? 140 : 300);
    }, 2600);
  }

  function money(n, currency = 'EUR', signed = false, type = null) {
    const abs = Math.abs(Number(n) || 0);
    const formatted = abs.toLocaleString('en-IE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const sym = { EUR: '€', USD: '$', GBP: '£', GHS: 'GH₵' }[currency] || currency + ' ';
    if (type === 'income' || (signed && Number(n) > 0)) return '+' + sym + formatted;
    if (type === 'expense' || (signed && Number(n) < 0)) return '−' + sym + formatted;
    return sym + formatted;
  }

  function greeting() {
    const h = new Date().getHours();
    if (h < 12) return 'Good morning';
    if (h < 18) return 'Good afternoon';
    return 'Good evening';
  }

  function applyTheme(theme) {
    const root = document.documentElement;
    let resolved = theme;
    if (theme === 'system') {
      resolved = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
    }
    root.setAttribute('data-theme', resolved);
    localStorage.setItem('nova-theme', theme);
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', resolved === 'light' ? '#F3F4F6' : '#0B0D10');
  }

  function initTheme() {
    const saved = localStorage.getItem('nova-theme') || 'system';
    applyTheme(saved);
    window.matchMedia('(prefers-color-scheme: light)').addEventListener('change', () => {
      if ((localStorage.getItem('nova-theme') || 'system') === 'system') applyTheme('system');
    });
  }

  function setStatus(state) {
    const el = document.getElementById('status-banner');
    if (!el) return;
    if (!state || state === 'online') {
      el.classList.remove('is-visible');
      el.dataset.state = '';
      el.textContent = '';
      return;
    }
    el.classList.add('is-visible');
    el.dataset.state = state;
    el.textContent = state === 'offline' ? 'Offline' : state === 'syncing' ? 'Syncing…' : 'Synced';
    if (state === 'synced') setTimeout(() => setStatus(navigator.onLine ? 'online' : 'offline'), 1600);
  }

  function syncModalBodyClass() {
    const open = !!document.querySelector('.modal.is-open, .palette.is-open');
    document.body.classList.toggle('modal-open', open);
  }

  function pinChrome() {
    const vv = window.visualViewport;
    let bottom = 0;
    if (vv) {
      bottom = Math.max(0, Math.round(window.innerHeight - vv.height - vv.offsetTop));
    }
    document.documentElement.style.setProperty('--nav-bottom', bottom + 'px');
  }

  function initChromePin() {
    pinChrome();
    window.addEventListener('resize', pinChrome, { passive: true });
    window.addEventListener('orientationchange', () => setTimeout(pinChrome, 50));
    if (window.visualViewport) {
      window.visualViewport.addEventListener('resize', pinChrome, { passive: true });
      window.visualViewport.addEventListener('scroll', pinChrome, { passive: true });
    }
    // iOS sometimes reports wrong insets on first paint
    setTimeout(pinChrome, 0);
    setTimeout(pinChrome, 120);
    setTimeout(pinChrome, 400);
  }

  function openModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.hidden = false;
    modal.classList.add('is-open');
    syncModalBodyClass();
    pinChrome();
    const focusable = modal.querySelector('input, select, button, textarea');
    if (focusable) focusable.focus({ preventScroll: true });
  }

  function closeModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.remove('is-open');
    syncModalBodyClass();
    const hide = () => { modal.hidden = true; };
    if (reduce()) hide();
    else setTimeout(hide, 140);
    pinChrome();
  }

  /* Command palette — instant, no animation */
  const COMMANDS = [
    { id: 'add', label: 'Add transaction', hint: 'Action' },
    { id: 'search', label: 'Search transactions', hint: 'Jump' },
    { id: 'analytics', label: 'View analytics', hint: 'Jump' },
    { id: 'budget', label: 'Create budget', hint: 'Action' },
    { id: 'goal', label: 'Create goal', hint: 'Action' },
    { id: 'theme', label: 'Toggle theme', hint: 'Preference' },
    { id: 'accounts', label: 'View accounts', hint: 'Jump' },
    { id: 'settings', label: 'Settings', hint: 'Jump' },
  ];

  let paletteActive = 0;
  let paletteFiltered = COMMANDS.slice();
  let onCommand = null;

  function renderPalette() {
    const list = document.getElementById('palette-results');
    if (!list) return;
    list.innerHTML = paletteFiltered.map((c, i) => `
      <li role="option" id="pal-${c.id}">
        <button type="button" class="palette__item${i === paletteActive ? ' is-active' : ''}" data-cmd="${c.id}" aria-selected="${i === paletteActive}">
          <span>${c.label}</span><span class="palette__hint">${c.hint}</span>
        </button>
      </li>`).join('');
    list.querySelectorAll('[data-cmd]').forEach((btn) => {
      btn.addEventListener('click', () => runCommand(btn.dataset.cmd));
    });
  }

  function openPalette() {
    const root = document.getElementById('command-palette');
    const input = document.getElementById('palette-input');
    if (!root) return;
    root.classList.add('is-open');
    root.hidden = false;
    syncModalBodyClass();
    paletteFiltered = COMMANDS.slice();
    paletteActive = 0;
    renderPalette();
    input.value = '';
    input.focus();
  }

  function closePalette() {
    const root = document.getElementById('command-palette');
    if (!root) return;
    root.classList.remove('is-open');
    root.hidden = true;
    syncModalBodyClass();
  }

  function runCommand(id) {
    closePalette();
    onCommand?.(id);
  }

  function initPalette(handler) {
    onCommand = handler;
    const input = document.getElementById('palette-input');
    document.querySelectorAll('[data-open-palette]').forEach((el) => {
      el.addEventListener('click', openPalette);
    });
    document.querySelectorAll('[data-palette-dismiss]').forEach((el) => {
      el.addEventListener('click', closePalette);
    });
    document.addEventListener('keydown', (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        const root = document.getElementById('command-palette');
        if (root?.classList.contains('is-open')) closePalette();
        else openPalette();
      }
      if (e.key === 'Escape') closePalette();
    });
    input?.addEventListener('input', () => {
      const q = input.value.trim().toLowerCase();
      paletteFiltered = !q ? COMMANDS.slice() : COMMANDS.filter((c) => c.label.toLowerCase().includes(q));
      paletteActive = 0;
      renderPalette();
    });
    input?.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        paletteActive = (paletteActive + 1) % Math.max(paletteFiltered.length, 1);
        renderPalette();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        paletteActive = (paletteActive - 1 + paletteFiltered.length) % Math.max(paletteFiltered.length, 1);
        renderPalette();
      } else if (e.key === 'Enter' && paletteFiltered[paletteActive]) {
        e.preventDefault();
        runCommand(paletteFiltered[paletteActive].id);
      }
    });
  }

  global.NovaUI = {
    toast, money, greeting, applyTheme, initTheme, setStatus,
    openModal, closeModal, initPalette, openPalette, closePalette, reduce,
    pinChrome, initChromePin,
  };
})(window);
