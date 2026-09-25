/**
 * Receipt capture + optional on-device OCR (Tesseract.js loaded on demand).
 */
(function (global) {
  'use strict';

  let pendingReceipt = null; // { base64, ocr, mime }
  let tesseractLoading = null;

  function getPending() {
    return pendingReceipt;
  }

  function clearPending() {
    pendingReceipt = null;
    const preview = document.getElementById('receipt-preview');
    const status = document.getElementById('receipt-status');
    if (preview) {
      preview.hidden = true;
      preview.removeAttribute('src');
    }
    if (status) status.textContent = '';
  }

  function setPreview(dataUrl) {
    const preview = document.getElementById('receipt-preview');
    if (!preview) return;
    preview.src = dataUrl;
    preview.hidden = false;
  }

  function setStatus(msg) {
    const status = document.getElementById('receipt-status');
    if (status) status.textContent = msg || '';
  }

  function compressImage(file, maxSide = 1280, quality = 0.72) {
    return new Promise((resolve, reject) => {
      const url = URL.createObjectURL(file);
      const img = new Image();
      img.onload = () => {
        URL.revokeObjectURL(url);
        let { width, height } = img;
        const scale = Math.min(1, maxSide / Math.max(width, height));
        width = Math.round(width * scale);
        height = Math.round(height * scale);
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0, width, height);
        const dataUrl = canvas.toDataURL('image/jpeg', quality);
        resolve(dataUrl);
      };
      img.onerror = () => {
        URL.revokeObjectURL(url);
        reject(new Error('Could not read image'));
      };
      img.src = url;
    });
  }

  function parseReceiptText(text) {
    const lines = String(text || '')
      .split(/\r?\n/)
      .map((l) => l.trim())
      .filter(Boolean);

    const amounts = [];
    const amountRe = /(?:EUR|USD|GBP|GHS|€|\$|£)?\s*(\d{1,5}[.,]\d{2})\b/gi;
    let m;
    const blob = lines.join(' ');
    while ((m = amountRe.exec(blob)) !== null) {
      const n = parseFloat(m[1].replace(',', '.'));
      if (!Number.isNaN(n) && n > 0 && n < 100000) amounts.push(n);
    }
    // Prefer the largest amount as a "total" heuristic
    const amount = amounts.length ? Math.max(...amounts).toFixed(2) : '';

    let date = '';
    const dateRe = /\b(\d{4}[-/.]\d{1,2}[-/.]\d{1,2}|\d{1,2}[-/.]\d{1,2}[-/.]\d{2,4})\b/;
    for (const line of lines) {
      const dm = line.match(dateRe);
      if (dm) {
        date = normalizeDate(dm[1]);
        if (date) break;
      }
    }

    // Merchant: first substantial line without only numbers/symbols
    let merchant = '';
    for (const line of lines.slice(0, 8)) {
      if (line.length < 3) continue;
      if (/^[\d\s.,€$£%-]+$/.test(line)) continue;
      if (/total|subtotal|tax|vat|change|cash|card/i.test(line)) continue;
      merchant = line.slice(0, 160);
      break;
    }

    return { amount, merchant, date, raw: text };
  }

  function normalizeDate(raw) {
    const s = raw.replace(/[/.]/g, '-');
    const parts = s.split('-').map((p) => p.padStart(2, '0'));
    if (parts.length !== 3) return '';
    if (parts[0].length === 4) {
      return `${parts[0]}-${parts[1]}-${parts[2]}`;
    }
    // dd-mm-yyyy or dd-mm-yy
    let y = parts[2];
    if (y.length === 2) y = '20' + y;
    return `${y}-${parts[1]}-${parts[0]}`;
  }

  async function loadTesseract() {
    if (global.Tesseract) return global.Tesseract;
    if (tesseractLoading) return tesseractLoading;
    tesseractLoading = new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js';
      s.async = true;
      s.onload = () => resolve(global.Tesseract);
      s.onerror = () => reject(new Error('OCR library failed to load'));
      document.head.appendChild(s);
    });
    return tesseractLoading;
  }

  async function runOcr(dataUrl) {
    setStatus('Reading receipt…');
    try {
      const Tesseract = await loadTesseract();
      const result = await Tesseract.recognize(dataUrl, 'eng', {
        logger: () => {},
      });
      const parsed = parseReceiptText(result?.data?.text || '');
      setStatus(parsed.amount ? 'Receipt scanned — check the fields' : 'Receipt attached — fill amount if needed');
      return parsed;
    } catch {
      setStatus('Receipt attached (scan unavailable)');
      return { amount: '', merchant: '', date: '', raw: '' };
    }
  }

  function applyParsed(parsed) {
    if (parsed.amount && document.getElementById('txn-amount') && !document.getElementById('txn-amount').value) {
      document.getElementById('txn-amount').value = parsed.amount;
    }
    if (parsed.merchant && document.getElementById('txn-merchant') && !document.getElementById('txn-merchant').value) {
      document.getElementById('txn-merchant').value = parsed.merchant;
    }
    if (parsed.date && document.getElementById('txn-date')) {
      document.getElementById('txn-date').value = parsed.date;
    }
    const expense = document.querySelector('#form-txn input[name="type"][value="expense"]');
    if (expense) expense.checked = true;
  }

  async function handleFile(file) {
    if (!file || !file.type.startsWith('image/')) {
      throw new Error('Please choose a receipt photo');
    }
    setStatus('Preparing image…');
    const dataUrl = await compressImage(file);
    setPreview(dataUrl);
    const parsed = await runOcr(dataUrl);
    applyParsed(parsed);
    pendingReceipt = {
      base64: dataUrl,
      ocr: parsed.raw || '',
      mime: 'image/jpeg',
    };
  }

  function bind() {
    const input = document.getElementById('receipt-input');
    const clearBtn = document.getElementById('receipt-clear');
    if (!input) return;

    input.addEventListener('change', async () => {
      const file = input.files?.[0];
      input.value = '';
      if (!file) return;
      try {
        await handleFile(file);
      } catch (e) {
        setStatus(e.message || 'Could not use that image');
        global.NovaUI?.toast(e.message || 'Could not use that image');
      }
    });

    clearBtn?.addEventListener('click', () => {
      clearPending();
      setStatus('');
    });
  }

  global.NovaReceipt = { bind, getPending, clearPending };
})(window);
