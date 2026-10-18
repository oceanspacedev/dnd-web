// Shared helpers for the Panduan Ubah Posisi recordings (port 8011, DB dnd_demo_posisi).
const rec = require('../lib.js');

const BASE = rec.baseUrl('posisi');
const OUT_DIR = rec.outDir('posisi');

function resetDb() {
  rec.resetDb('posisi');
}

// Like rec.login, but does not wait for the "load" event (external avatar/font requests can stall it).
async function login(page, username) {
  for (let attempt = 1; attempt <= 3; attempt++) {
    try {
      await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
      await rec.settle(page, 300);
      await page.locator('input[type=text]').first().fill(username);
      await page.locator('input[type=password]').first().fill(rec.PASSWORD);
      await Promise.all([
        page.waitForURL(/\/admin(\?.*)?$/, { waitUntil: 'domcontentloaded' }),
        page.locator('button[type=submit]').first().click(),
      ]);
      await rec.settle(page);
      return;
    } catch (e) {
      if (attempt === 3) throw e;
    }
  }
}

async function setSidebar(page, expanded) {
  const btn = page.locator('button[aria-controls="mekaya-desktop-sidebar"]');
  if ((await btn.getAttribute('aria-expanded')) !== String(expanded)) {
    await btn.click();
    await rec.settle(page);
  }
}
const collapseSidebar = (page) => setSidebar(page, false);
const expandSidebar = (page) => setSidebar(page, true);

// Scroll the panel's scroll container so `locator` sits `top` px from the top of the viewport.
async function scrollTo(page, locator, top = 70) {
  await locator.first().evaluate((el, top) => {
    let box = el.parentElement;
    while (box && !(/(auto|scroll)/.test(getComputedStyle(box).overflowY) && box.scrollHeight > box.clientHeight)) box = box.parentElement;
    const target = box || document.scrollingElement;
    target.scrollTop += el.getBoundingClientRect().top - top;
  }, top);
  await page.waitForTimeout(250);
}

// Red outline (no transition, so the screenshot shows the final colour). Accepts one or many locators.
async function highlight(locators) {
  for (const l of [].concat(locators)) {
    await l.evaluate((el) => {
      el.dataset.recStyle = el.getAttribute('style') || '';
      el.style.setProperty('transition', 'none', 'important');
      el.style.setProperty('outline', '3px solid #ef4444', 'important');
      el.style.setProperty('outline-offset', '2px', 'important');
    });
  }
}

async function unhighlight(locators) {
  for (const l of [].concat(locators)) {
    await l.evaluate((el) => {
      if (el.dataset.recStyle) el.setAttribute('style', el.dataset.recStyle); else el.removeAttribute('style');
      delete el.dataset.recStyle;
    }).catch(() => {});
  }
}

// Highlight, capture, click, settle. Keeps the target clear of the sticky topbar.
async function clickStep(page, r, locator, seconds = 1.2, { scroll = true } = {}) {
  if (scroll) {
    const box = await locator.boundingBox();
    if (!box || box.y < 70 || box.y + box.height > 600) await scrollTo(page, locator, 120);
  }
  await highlight(locator);
  await r.snap(page, seconds);
  await unhighlight(locator);
  await locator.click();
  await rec.settle(page);
}

async function waitNotification(page, text) {
  await page.locator('.fi-no-notification', { hasText: text }).first().waitFor({ state: 'visible' });
  await page.waitForTimeout(800); // let the slide-in animation finish
}

async function waitDropdownsClosed(page) {
  await page.waitForFunction(() => [...document.querySelectorAll('.fi-dropdown-panel')].every((p) => p.offsetParent === null || getComputedStyle(p).display === 'none'), null, { timeout: 5000 }).catch(() => {});
  await page.waitForTimeout(300);
}

module.exports = { rec, BASE, OUT_DIR, resetDb, login, collapseSidebar, expandSidebar, scrollTo, highlight, unhighlight, clickStep, waitNotification, waitDropdownsClosed };
