// Helpers for the Panduan Akses Data recordings (wraps the shared rec lib).
const rec = require('../lib.js');

const BASE = rec.baseUrl('akses');

// Login with a wait for Livewire to hydrate, retrying once if the first submit does not navigate.
async function login(page, username) {
  for (let attempt = 1; attempt <= 3; attempt++) {
    await page.goto('/admin/login');
    await rec.settle(page, 600);
    await page.locator('input[type=text]').first().fill(username);
    await page.locator('input[type=password]').first().fill(rec.PASSWORD);
    await page.locator('button[type=submit]').first().click();
    try {
      await page.waitForURL(/\/admin(\?.*)?$/, { timeout: 20000 });
      await rec.settle(page, 600);
      return;
    } catch (e) {
      await page.screenshot({ path: rec.debugPath(`login_fail_${username}_${attempt}.png`) }).catch(() => {});
      if (attempt === 3) throw e;
    }
  }
}

// Scroll the Filament table container fully left/right.
async function scrollTable(page, where) {
  await page.evaluate((w) => {
    const el = document.querySelector('.fi-ta-content-ctn');
    if (el) el.scrollLeft = w === 'right' ? el.scrollWidth : 0;
  }, where);
  await page.waitForTimeout(250);
}

// Outline several elements at once (e.g. a whole column); returns a function to remove it.
async function outlineAll(page, selector) {
  await page.evaluate((sel) => {
    document.querySelectorAll(sel).forEach((el) => {
      el.dataset.recOutline = el.style.outline;
      el.style.outline = '3px solid #ef4444';
      el.style.outlineOffset = '-3px';
    });
  }, selector);
  return () => page.evaluate((sel) => {
    document.querySelectorAll(sel).forEach((el) => {
      el.style.outline = el.dataset.recOutline || '';
      el.style.outlineOffset = '';
    });
  }, selector);
}

// Scroll so that `locator` sits about `offset` px below the top of the viewport.
async function scrollToTop(page, locator, offset = 90) {
  const box = await locator.boundingBox();
  if (!box) return;
  await page.evaluate(([y, off]) => window.scrollBy(0, y - off), [box.y, offset]);
  await page.waitForTimeout(300);
}

// The app shell is viewport-high; content scrolls inside this container. The window itself must stay at 0.
async function resetWindow(page) {
  await page.evaluate(() => window.scrollTo(0, 0));
}

// Scroll the inner content container so `locator` sits `offset` px below the top of the viewport.
async function reveal(page, locator, offset = 120) {
  await locator.waitFor({ state: 'visible' });
  await locator.evaluate((el, off) => {
    let sc = el.parentElement;
    while (sc && !(sc.scrollHeight > sc.clientHeight + 5 && ['auto', 'scroll'].includes(getComputedStyle(sc).overflowY) && !sc.classList.contains('mky-si-scroll'))) sc = sc.parentElement;
    window.scrollTo(0, 0);
    if (!sc) return;
    const r = el.getBoundingClientRect();
    sc.scrollTop += r.top - off;
    // Horizontal: bring the element inside a horizontally scrolled table container.
    const tc = el.closest('.fi-ta-content-ctn');
    if (tc) {
      const tr = tc.getBoundingClientRect();
      const er = el.getBoundingClientRect();
      if (er.right > tr.right - 8) tc.scrollLeft += er.right - tr.right + 24;
      if (er.left < tr.left) tc.scrollLeft -= tr.left - er.left + 24;
    }
  }, offset);
  await page.waitForTimeout(250);
  await resetWindow(page);
}

// Red outline without the theme's CSS transition (otherwise the snapshot catches a half-faded colour).
async function hl(locator) {
  await locator.evaluate((el) => {
    el.dataset.recOutline = el.style.outline;
    el.dataset.recTransition = el.style.transition;
    el.style.transition = 'none';
    el.style.outline = '3px solid #ef4444';
    el.style.outlineOffset = '2px';
  });
}

async function unhl(locator) {
  await locator.evaluate((el) => {
    el.style.outline = el.dataset.recOutline || '';
    el.style.outlineOffset = '';
    el.style.transition = el.dataset.recTransition || '';
  }).catch(() => {});
}

// Park the mouse on an empty spot so hover styles do not show in the next frame.
async function parkMouse(page) {
  await page.mouse.move(1090, 600);
}

async function snapHighlighted(r, page, locator, seconds) {
  await hl(locator);
  await page.waitForTimeout(80);
  await r.snap(page, seconds);
  await unhl(locator);
}

// Highlight, capture, click, settle (keeping the window unscrolled).
async function step(r, page, locator, seconds = 1.2, { offset = 120, noReveal = false } = {}) {
  if (!noReveal) await reveal(page, locator, offset);
  await parkMouse(page);
  await snapHighlighted(r, page, locator, seconds);
  await locator.click();
  await rec.settle(page, 700);
  await parkMouse(page);
  await resetWindow(page);
}

// Scroll the main content container back to the top.
async function scrollContentTop(page) {
  await page.evaluate(() => {
    const main = document.querySelector('.fi-main') || document.querySelector('main');
    let sc = main;
    while (sc && !(sc.scrollHeight > sc.clientHeight + 5 && ['auto', 'scroll'].includes(getComputedStyle(sc).overflowY))) sc = sc.parentElement;
    if (sc) sc.scrollTop = 0;
    window.scrollTo(0, 0);
  });
  await page.waitForTimeout(250);
}

// Draw one red box around the union of the matched elements (clipped to the viewport), e.g. a table column.
async function boxOverlay(page, selector) {
  await page.evaluate((sel) => {
    const els = [...document.querySelectorAll(sel)].filter((e) => e.offsetParent !== null);
    if (els.length === 0) return;
    let top = Infinity; let left = Infinity; let right = -Infinity; let bottom = -Infinity;
    for (const e of els) {
      const r = e.getBoundingClientRect();
      top = Math.min(top, r.top); left = Math.min(left, r.left); right = Math.max(right, r.right); bottom = Math.max(bottom, r.bottom);
    }
    top = Math.max(top, 58); bottom = Math.min(bottom, window.innerHeight - 4);
    const d = document.createElement('div');
    d.id = 'rec-box-overlay';
    Object.assign(d.style, { position: 'fixed', top: top + 'px', left: left + 'px', width: (right - left) + 'px', height: (bottom - top) + 'px',
      border: '3px solid #ef4444', borderRadius: '8px', pointerEvents: 'none', zIndex: 9999, boxSizing: 'border-box' });
    document.body.appendChild(d);
  }, selector);
  return () => page.evaluate(() => document.getElementById('rec-box-overlay')?.remove());
}

// Index (1-based) of the table column whose header text is `label`.
async function columnIndex(page, label) {
  const idx = await page.locator('.fi-ta-table thead th').evaluateAll((ths, l) => ths.findIndex((th) => th.innerText.trim() === l), label);
  if (idx < 0) throw new Error('column not found: ' + label);
  return idx + 1;
}

module.exports = { scrollContentTop, boxOverlay, columnIndex,  rec, BASE, login, scrollTable, outlineAll, scrollToTop, resetWindow, reveal, step, hl, unhl, snapHighlighted, parkMouse };
