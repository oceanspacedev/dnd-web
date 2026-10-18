// Records the GIFs for Panduan KPI Manajerial (/admin/panduan-kpi-atasan) into public/images/kpi-guides.
// Usage: node kpi-manajerial.js [gif-name ...] [--keep-db]   (default: all six)
//   node kpi-manajerial.js rebuild <gif-name>...  rebuilds GIFs from the frames already recorded (no browser, no DB).
// Server: sh serve.sh kpimanajerial (port 8014, DB dnd_demo_kpimanajerial, WhatsApp -> local stub on 8019).
//
// Actor: demo_manager (GARDA SITOMPUL, MANAGER). Target: PRIMA LURHUR HAKIM, the only "Admin Gudang", two levels
// below the manager (via demo_teamleader). The KPI GIFs start signed in (password login off camera); the review GIF
// starts on the login page and signs in with the WhatsApp OTP, read from the local stub.
const rec = require('../lib.js');
const path = require('path');

const GUIDE = 'kpimanajerial';
const BASE = rec.baseUrl(GUIDE);
const OUT_DIR = rec.outDir(GUIDE);
const ACTOR = 'demo_manager';
const ACTOR_PHONE = '0812-0000-0003'; // fake demo number (demo_seed.php kpi layer)
const TARGET = { search: 'Admin', option: 'Admin Gudang - PRIMA LURHUR HAKIM', name: 'PRIMA LURHUR HAKIM', reviewSearch: 'prima' };
// Pinned periode so a later re-run still matches the guide text (Month 10/2026, review Periode Oktober 2026).
const KPI_MONTH = { monthIndex: 9, year: 2026, display: '10/2026' };
const REVIEW_PERIODE = '2026-10';
const ADD_ROW = 'Tambahkan ke kPI Descriptions';

// Per-tab ids (KpiResource::createForm): percentage field, description select / count type / value plan suffixes.
const TABS = {
  'MAIN JOB': { percentage: 'form.percentageMain', desc: 'kpi_description_id_main', count: 'count_typeMain', plan: 'value_planMain' },
  ADMINISTRATION: { percentage: 'form.percentageAdm', desc: 'kpi_description_id_adm', count: 'count_type', plan: 'value_plan' },
  REPORTING: { percentage: 'form.percentageRep', desc: 'kpi_description_id_rep', count: 'count_typeRep', plan: 'value_planRep' },
};

// ---------------------------------------------------------------------------------------------------------------
// Page helpers

// page.goto without waiting for the 'load' event (external fonts/avatar can stall it).
async function go(page, url, ms = 800) {
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
  await page.waitForFunction(() => window.Livewire && window.Alpine, null, { timeout: 30000 });
  await rec.settle(page, ms);
}

// Password login off camera (aturan.js style, with retries).
async function login(page, username) {
  for (let attempt = 1; ; attempt++) {
    try {
      await go(page, '/admin/login', 300);
      await page.locator('input[type=text]').first().fill(username);
      await page.locator('input[type=password]').first().fill(rec.PASSWORD);
      await Promise.all([
        page.waitForURL(/\/admin(\?.*)?$/, { waitUntil: 'commit', timeout: 10000 }),
        page.locator('button[type=submit]').first().click(),
      ]);
      await rec.settle(page);
      return;
    } catch (e) {
      await page.screenshot({ path: rec.debugPath('login-fail-kpimanajerial-' + attempt + '.png') }).catch(() => {});
      console.log('login attempt', attempt, 'failed at', page.url(), e.message.split('\n')[0]);
      if (attempt >= 5) throw e;
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

// Scroll the element's scroll container (or the window) so the element's top sits at viewport y.
async function scrollToY(locator, y) {
  await locator.first().evaluate((el, y) => {
    let p = el.parentElement;
    while (p && !(['auto', 'scroll'].includes(getComputedStyle(p).overflowY) && p.scrollHeight > p.clientHeight)) p = p.parentElement;
    const delta = el.getBoundingClientRect().top - y;
    if (p) p.scrollTop += delta; else window.scrollBy(0, delta);
  }, y);
  await locator.page().waitForTimeout(300);
}

// Scroll the element's scroll container (or the window) to the very bottom: footer buttons fully visible.
async function scrollToBottom(locator) {
  await locator.first().evaluate((el) => {
    let p = el.parentElement;
    while (p && !(['auto', 'scroll'].includes(getComputedStyle(p).overflowY) && p.scrollHeight > p.clientHeight)) p = p.parentElement;
    if (p) p.scrollTop = p.scrollHeight; else window.scrollTo(0, document.documentElement.scrollHeight);
  });
  await locator.page().waitForTimeout(300);
}

async function blur(page) {
  await page.evaluate(() => document.activeElement && document.activeElement.blur && document.activeElement.blur());
  await page.waitForTimeout(150);
}

// Red box drawn over the element(s) as a fixed overlay (Filament inputs/buttons clip or reset outlines).
async function mark(page, ...locators) {
  for (const locator of locators) {
    await locator.first().evaluate((el) => {
      const r = el.getBoundingClientRect();
      const box = document.createElement('div');
      box.className = 'rec-mark';
      Object.assign(box.style, {
        position: 'fixed', left: (r.left - 4) + 'px', top: (r.top - 4) + 'px', width: (r.width + 8) + 'px', height: (r.height + 8) + 'px',
        border: '3px solid #ef4444', borderRadius: '8px', zIndex: 2147483647, pointerEvents: 'none', boxSizing: 'border-box',
      });
      document.body.appendChild(box);
    });
  }
}

// One red box around several elements (e.g. a few table rows).
async function markUnion(page, locators, pad = 4) {
  const rects = [];
  // Clip each rect to its horizontally scrolling ancestor (wide tables), so the box never runs off the table.
  for (const locator of locators) rects.push(await locator.first().evaluate((el) => {
    const r = el.getBoundingClientRect();
    let [l, t, rr, b] = [r.left, r.top, r.right, r.bottom];
    for (let p = el.parentElement; p; p = p.parentElement) {
      const ox = getComputedStyle(p).overflowX;
      if (ox === 'auto' || ox === 'scroll') { const c = p.getBoundingClientRect(); l = Math.max(l, c.left); rr = Math.min(rr, c.right); break; }
    }
    return [l, t, rr, b];
  }));
  const [l, t, rr, b] = [Math.min(...rects.map((x) => x[0])), Math.min(...rects.map((x) => x[1])), Math.max(...rects.map((x) => x[2])), Math.max(...rects.map((x) => x[3]))];
  await page.evaluate(([l, t, rr, b]) => {
    const box = document.createElement('div');
    box.className = 'rec-mark';
    Object.assign(box.style, {
      position: 'fixed', left: l + 'px', top: t + 'px', width: (rr - l) + 'px', height: (b - t) + 'px',
      border: '3px solid #ef4444', borderRadius: '8px', zIndex: 2147483647, pointerEvents: 'none', boxSizing: 'border-box',
    });
    document.body.appendChild(box);
  }, [l - pad, t - pad, rr + pad, b + pad]);
}

async function unmark(page) {
  await page.evaluate(() => document.querySelectorAll('.rec-mark').forEach((el) => el.remove()));
}

// Mark, capture, unmark, click, settle. `scroll: false` keeps the current scroll position.
async function clickStep(page, r, locator, seconds = 1.2, { scroll = true, settleMs = 400 } = {}) {
  if (scroll) {
    await locator.evaluate((el) => el.scrollIntoView({ block: 'nearest', inline: 'nearest' }));
    await page.waitForTimeout(200);
  }
  await mark(page, locator);
  await r.snap(page, seconds);
  await unmark(page);
  await locator.click();
  await parkMouse(page);
  await rec.settle(page, settleMs);
}

// Move the pointer to the empty bottom-right corner so no hover state shows in the next frame.
async function parkMouse(page) {
  await page.mouse.move(1096, 615);
}

// Wait until the notifications are gone (they last a few seconds), so a "solution" frame shows no stale error.
async function waitNotificationsGone(page) {
  await page.waitForFunction(() => document.querySelectorAll('.fi-no-notification').length === 0, null, { timeout: 15000 }).catch(() => console.log('notifications still visible'));
  await page.waitForTimeout(300);
}

// The KPI table is ~16 px wider than the 1100 px recording even with the sidebar collapsed, so its last column
// (Lihat / Ubah) is cut off. Scale only the table's scroll container down just enough to fit (about 0.98); the overlay
// boxes stay correct because they are appended to <body>. Livewire morphs drop the inline style, so call this right
// before each snap of the list.
async function fitTable(page) {
  const zoom = await page.evaluate(() => {
    const ctn = document.querySelector('.fi-ta-content-ctn');
    if (!ctn) return null;
    ctn.style.zoom = '';
    if (ctn.scrollWidth <= ctn.clientWidth) return 1;
    const z = Math.floor((ctn.clientWidth / ctn.scrollWidth) * 100) / 100;
    ctn.style.zoom = String(z);
    return z;
  });
  await page.waitForTimeout(200);
  const overflow = await page.evaluate(() => { const c = document.querySelector('.fi-ta-content-ctn'); return c ? c.scrollWidth - c.clientWidth : 0; });
  console.log('table zoom', zoom, 'overflow after', overflow);
}

// Mark the given elements, capture, unmark.
async function markSnap(page, r, locators, seconds, { union = false } = {}) {
  if (union) await markUnion(page, locators); else await mark(page, ...locators);
  await r.snap(page, seconds);
  await unmark(page);
}

// Filament field wrapper (label + input + hint/helper) for an input id.
function field(page, id) {
  return page.locator('.fi-fo-field').filter({ has: page.locator('[id="' + id + '"]') }).last();
}

function inputWrap(page, id) {
  return field(page, id).locator('.fi-input-wrp').first();
}

async function waitNotification(page, text) {
  const note = page.locator('.fi-no-notification', { hasText: text }).first();
  await note.waitFor({ state: 'visible', timeout: 20000 });
  await page.waitForTimeout(900); // let the slide-in animation finish
  return note;
}

// Count Livewire round trips (to prove a click was stopped by the browser before reaching the server).
function trackLivewire(page) {
  const counter = { n: 0 };
  page.on('request', (req) => { if (req.method() === 'POST' && /livewire.*update/.test(req.url())) counter.n++; });
  return counter;
}

async function livewireUpdate(page, action) {
  const resp = page.waitForResponse((res) => res.request().method() === 'POST' && /livewire.*update/.test(res.url()), { timeout: 20000 });
  await action();
  await resp;
  await rec.settle(page, 300);
}

// ---------------------------------------------------------------------------------------------------------------
// Buat Kpi form helpers

const activeTab = (page) => page.locator('.fi-sc-tabs-tab.fi-active');
const rows = (page) => activeTab(page).locator('.fi-fo-table-repeater tbody tr');
const tabButton = (page, name) => page.getByRole('tab', { name, exact: true });
const addRowButton = (page) => activeTab(page).getByRole('button', { name: ADD_ROW });
const submitButton = (page) => page.locator('button[type=submit]').filter({ hasText: /^\s*Buat\s*$/ }).first();
const descSelect = (row, tab) => row.locator(`[id$=".${TABS[tab].desc}"]`);
const countSelect = (row, tab) => row.locator(`select[id$=".${TABS[tab].count}"]`);
const planInput = (row, tab) => row.locator(`input[id$=".${TABS[tab].plan}"]`);
const percentageWrap = (page, tab) => inputWrap(page, TABS[tab].percentage);
const percentageInput = (page, tab) => page.locator('[id="' + TABS[tab].percentage + '"]');

async function openCreate(page) {
  await go(page, '/admin/kpis/create', 1000);
  await setSidebar(page, false);
}

// Job Position: open the searchable select, type, return the option (caller clicks it, with or without a frame).
async function openPositionOption(page) {
  await page.locator('#form\\.position_id').click();
  await rec.settle(page, 300);
  await page.keyboard.type(TARGET.search, { delay: 60 });
  await rec.settle(page, 500);
  return page.locator('.fi-select-input-option:visible', { hasText: TARGET.option }).first();
}

// Month: open the calendar on Oktober 2026 and return the day-1 cell (caller clicks it).
async function openMonthDay(page) {
  await page.locator('.fi-fo-date-time-picker-trigger').filter({ has: page.locator('#form\\.date') }).click();
  await rec.settle(page, 400);
  const panel = page.locator('.fi-fo-date-time-picker-panel:visible');
  const monthSelect = panel.locator('.fi-fo-date-time-picker-month-select');
  if ((await monthSelect.inputValue()) !== String(KPI_MONTH.monthIndex)) await monthSelect.selectOption(String(KPI_MONTH.monthIndex));
  const year = panel.locator('.fi-fo-date-time-picker-year-input');
  if ((await year.inputValue()) !== String(KPI_MONTH.year)) { await year.fill(String(KPI_MONTH.year)); await year.blur(); }
  await page.waitForTimeout(300);
  return panel.locator('.fi-fo-date-time-picker-calendar-day').filter({ hasText: /^\s*1\s*$/ }).first();
}

async function closeMonth(page) {
  await page.keyboard.press('Escape');
  await rec.settle(page, 300);
  const value = await page.locator('#form\\.date').inputValue();
  if (value !== KPI_MONTH.display) throw new Error('Month is ' + value + ', expected ' + KPI_MONTH.display);
}

// Job Position + Month without frames.
async function fillHeader(page) {
  await (await openPositionOption(page)).click();
  await rec.settle(page, 300);
  await (await openMonthDay(page)).click();
  await closeMonth(page);
}

async function switchTab(page, tab) {
  await tabButton(page, tab).click();
  await page.waitForTimeout(400);
}

async function addRow(page) {
  const before = await rows(page).count();
  await livewireUpdate(page, () => addRowButton(page).click());
  await rows(page).nth(before).waitFor();
  return rows(page).nth(before);
}

async function pickDescription(page, row, tab, description) {
  await descSelect(row, tab).click();
  await rec.settle(page, 300);
  const option = page.locator('.fi-select-input-option:visible', { hasText: description }).first();
  await option.click();
  await rec.settle(page, 300);
}

async function setCountType(page, row, tab, type, plan) {
  await livewireUpdate(page, () => countSelect(row, tab).selectOption(type));
  if (plan !== undefined) {
    await planInput(row, tab).fill(String(plan));
    await planInput(row, tab).blur();
  }
  await rec.settle(page, 200);
}

// A whole KPI row without frames.
async function fillRow(page, tab, description, type, plan) {
  const row = await addRow(page);
  await pickDescription(page, row, tab, description);
  await setCountType(page, row, tab, type, plan);
  return row;
}

function logToasts(page, label) {
  return page.locator('.fi-no-notification').evaluateAll((els) => els.map((e) => e.innerText.replace(/\s+/g, ' ').trim()))
    .then((t) => console.log(label, JSON.stringify(t)));
}

// ---------------------------------------------------------------------------------------------------------------
// GIF 1 (SOP 1): a manager creates one month's KPI for Admin Gudang, end to end.
async function kpiFlowLengkap(page, r) {
  await go(page, '/admin/kpis', 1200);
  await setSidebar(page, false);
  await page.locator('tr.fi-ta-row').first().waitFor();
  await rec.settle(page, 500);
  await fitTable(page);

  await clickStep(page, r, page.getByRole('link', { name: /^Buat kpi$/i }), 1.2, { scroll: false });
  await page.waitForURL(/\/admin\/kpis\/create$/, { waitUntil: 'commit' });
  await rec.settle(page, 1000);

  // Job Position: posisi + anggota tim pemegangnya.
  const option = await openPositionOption(page);
  await clickStep(page, r, option, 1.3, { scroll: false });

  // Month: klik tanggal mana saja di bulan periode (field menampilkan bulan/tahun).
  const day = await openMonthDay(page);
  await clickStep(page, r, day, 1.2, { scroll: false });
  await closeMonth(page);

  // MAIN JOB: Percentage % 60, lalu Tambahkan ke kPI Descriptions.
  await scrollToY(tabButton(page, 'MAIN JOB'), 150);
  await percentageInput(page, 'MAIN JOB').fill('60');
  await blur(page);
  await mark(page, percentageWrap(page, 'MAIN JOB'));
  await mark(page, addRowButton(page));
  await r.snap(page, 1.3);
  await unmark(page);
  const row1 = await addRow(page);

  // Baris 1: Tiket selesai per bulan.
  await scrollToY(row1, 250);
  // The option list opens downwards and the page cannot scroll further: filter it so nothing is cut off.
  await descSelect(row1, 'MAIN JOB').click();
  await rec.settle(page, 300);
  await page.keyboard.type('tiket', { delay: 60 });
  await rec.settle(page, 500);
  await clickStep(page, r, page.locator('.fi-select-input-option:visible', { hasText: 'Tiket selesai per bulan' }).first(), 1.1, { scroll: false });

  // Count Type RESULT -> Value Plan aktif -> 40.
  await setCountType(page, row1, 'MAIN JOB', 'RESULT', 40);
  await blur(page);
  await markUnion(page, [countSelect(row1, 'MAIN JOB'), planInput(row1, 'MAIN JOB')], 6);
  await r.snap(page, 1.3);
  await unmark(page);

  // Baris 2: indikator baru lewat ikon + (jendela Buat).
  const row2 = await addRow(page);
  await scrollToY(row2, 330);
  await blur(page);
  await clickStep(page, r, row2.getByRole('button', { name: 'Buat', exact: true }), 1.2, { scroll: false, settleMs: 700 });
  const modal = page.locator('.fi-modal-window:visible').filter({ hasText: 'Lower is Better' });
  await modal.waitFor();
  const description = modal.locator('input[id$=".description"]');
  await description.fill('Insiden kritikal');
  const toggle = modal.locator('[id$=".is_negative"]');
  await toggle.click();
  await page.waitForTimeout(400);
  console.log('negative toggle', await toggle.getAttribute('aria-checked'));
  await blur(page);
  const modalSubmit = modal.getByRole('button', { name: 'Buat', exact: true });
  await mark(page, description, toggle.locator('xpath=..'), modalSubmit);
  await r.snap(page, 1.6);
  await unmark(page);
  await livewireUpdate(page, () => modalSubmit.click());
  await modal.waitFor({ state: 'hidden' });
  await rec.settle(page, 500);
  console.log('row 2 description', (await descSelect(row2, 'MAIN JOB').innerText()).trim());

  await setCountType(page, row2, 'MAIN JOB', 'RESULT', 3);
  await blur(page);
  await scrollToY(row1, 250);
  await markSnap(page, r, [row2], 1.3);

  // ADMINISTRATION: Percentage % 20 + Timesheet lengkap (NON, Value Plan tidak aktif).
  await scrollToY(tabButton(page, 'MAIN JOB'), 150);
  await clickStep(page, r, tabButton(page, 'ADMINISTRATION'), 1.0, { scroll: false });
  await percentageInput(page, 'ADMINISTRATION').fill('20');
  const admRow = await fillRow(page, 'ADMINISTRATION', 'Timesheet lengkap', 'NON');
  console.log('adm value plan disabled', await planInput(admRow, 'ADMINISTRATION').isDisabled());
  await blur(page);
  await scrollToY(tabButton(page, 'ADMINISTRATION'), 150);
  await mark(page, percentageWrap(page, 'ADMINISTRATION'), admRow);
  await r.snap(page, 1.5);
  await unmark(page);

  // REPORTING: Percentage % 20 + Laporan bulanan tepat waktu (RESULT, plan 1).
  await clickStep(page, r, tabButton(page, 'REPORTING'), 1.0, { scroll: false });
  await percentageInput(page, 'REPORTING').fill('20');
  const repRow = await fillRow(page, 'REPORTING', 'Laporan bulanan tepat waktu', 'RESULT', 1);
  await blur(page);
  await scrollToY(tabButton(page, 'REPORTING'), 150);
  await mark(page, percentageWrap(page, 'REPORTING'), repRow);
  await r.snap(page, 1.5);
  await unmark(page);

  // Buat (footer).
  const submit = submitButton(page);
  await submit.evaluate((el) => el.scrollIntoView({ block: 'center' }));
  await page.waitForTimeout(300);
  await mark(page, submit);
  await r.snap(page, 1.2);
  await unmark(page);
  await submit.click();
  await page.waitForURL(/\/admin\/kpis$/, { waitUntil: 'commit', timeout: 30000 });
  const note = await waitNotification(page, 'KPI created');
  const created = page.locator('tr.fi-ta-row', { hasText: TARGET.name });
  await created.nth(2).waitFor({ timeout: 20000 });
  await rec.settle(page, 300);
  console.log('toast', (await note.innerText()).replace(/\s+/g, ' '));
  console.log('rows', JSON.stringify(await created.evaluateAll((els) => els.map((e) => e.innerText.replace(/\s+/g, ' ').trim()))));
  await parkMouse(page);
  await fitTable(page);
  await mark(page, note);
  await markUnion(page, [created.nth(0), created.nth(1), created.nth(2)], 0);
  await r.snap(page, 2.8);
  await unmark(page);
}

// Error 1: required fields empty. Every tab already holds one complete KPI row (an empty tab is skipped without any
// error, so the "solution" must not suggest a form without rows); only Percentage %, Job Position and Month are empty.
// Browser bubble on Percentage % first, then the server toast for Job Position + Month.
async function errorRequiredFields(page, r) {
  const lw = trackLivewire(page);
  await openCreate(page);
  // Off camera: one row per tab, as in GIF 1. Percentage %, Job Position and Month stay empty.
  await fillRow(page, 'MAIN JOB', 'Tiket selesai per bulan', 'RESULT', 40);
  await switchTab(page, 'ADMINISTRATION');
  await fillRow(page, 'ADMINISTRATION', 'Timesheet lengkap', 'NON');
  await switchTab(page, 'REPORTING');
  await fillRow(page, 'REPORTING', 'Laporan bulanan tepat waktu', 'RESULT', 1);
  await switchTab(page, 'MAIN JOB');
  await blur(page);
  // Bottom of the page: tabs, the MAIN JOB row and the footer Buat in view.
  await scrollToBottom(page.locator('.fi-sc-tabs').first());

  const submit = submitButton(page);
  console.log('invalid before submit', JSON.stringify(await page.evaluate(() => [...document.querySelectorAll('form :invalid')].map((e) => e.id))));
  const before = lw.n;
  await mark(page, submit);
  await r.snap(page, 1.2);
  await unmark(page);
  await submit.click();
  await parkMouse(page);
  await page.waitForTimeout(600);
  // The browser stops the submit (no request) and the tabs jump to the tab whose Percentage % it focuses.
  const focused = await page.evaluate(() => document.activeElement && document.activeElement.id);
  const focusedTab = Object.keys(TABS).find((t) => TABS[t].percentage === focused);
  if (!focusedTab) throw new Error('expected the browser to focus a Percentage % field, got ' + focused);
  const requests = lw.n - before;
  console.log('requests after empty submit', requests, 'focused', focused, 'tab', focusedTab,
    'message', await percentageInput(page, focusedTab).evaluate((e) => e.validationMessage));
  if (requests !== 0) throw new Error('the empty submit reached the server');
  await mark(page, tabButton(page, focusedTab), percentageWrap(page, focusedTab));
  await r.snap(page, 1.8);
  await unmark(page);

  // Isi Percentage % di ketiga tab (setiap tab sudah berisi satu baris KPI).
  for (const [tab, value] of [['MAIN JOB', 60], ['ADMINISTRATION', 20], ['REPORTING', 20]]) {
    await switchTab(page, tab);
    await percentageInput(page, tab).fill(String(value));
    await blur(page);
    await scrollToBottom(page.locator('.fi-sc-tabs').first());
    await markSnap(page, r, [tabButton(page, tab), percentageWrap(page, tab), rows(page).first()], tab === 'MAIN JOB' ? 1.2 : 1.0);
  }

  // Buat lagi: Job Position dan Month masih kosong -> notifikasi dari server.
  const before2 = lw.n;
  await clickStep(page, r, submit, 0.9, { scroll: false });
  const note = await waitNotification(page, 'Error occurred');
  console.log('requests', lw.n - before2, 'toast', (await note.innerText()).replace(/\s+/g, ' '));
  // Header labels at y ~115: Job Position and Month below the toast, the REPORTING row and its add button in view.
  await scrollToY(field(page, 'form.position_id'), 115);
  await blur(page);
  await mark(page, note, inputWrap(page, 'form.position_id'), inputWrap(page, 'form.date'));
  await r.snap(page, 2.4);
  await unmark(page);

  // Solusi: pilih Job Position dan Month. Form kini lengkap: header, Percentage % dan satu baris KPI di setiap tab.
  await waitNotificationsGone(page);
  await fillHeader(page);
  await blur(page);
  await scrollToY(field(page, 'form.position_id'), 115);
  await mark(page, inputWrap(page, 'form.position_id'), inputWrap(page, 'form.date'));
  await r.snap(page, 1.8);
  await unmark(page);
}

// Filled header + 60/20/20 without frames; ends on MAIN JOB.
async function prepareValidForm(page, { reporting = 20 } = {}) {
  await openCreate(page);
  await fillHeader(page);
  await percentageInput(page, 'MAIN JOB').fill('60');
  await switchTab(page, 'ADMINISTRATION');
  await percentageInput(page, 'ADMINISTRATION').fill('20');
  await switchTab(page, 'REPORTING');
  await percentageInput(page, 'REPORTING').fill(String(reporting));
  await switchTab(page, 'MAIN JOB');
}

// Error 2: KPI Description left empty -> server toast "The kPI Description field is required."
async function errorKpiDescription(page, r) {
  const lw = trackLivewire(page);
  await prepareValidForm(page);
  const row = await addRow(page);
  await setCountType(page, row, 'MAIN JOB', 'RESULT', 40);
  await blur(page);
  await scrollToY(row, 380);

  await markSnap(page, r, [descSelect(row, 'MAIN JOB')], 1.5);

  const submit = submitButton(page);
  const before = lw.n;
  await clickStep(page, r, submit, 0.9, { scroll: false });
  const note = await waitNotification(page, 'Error occurred');
  console.log('requests', lw.n - before, 'toast', (await note.innerText()).replace(/\s+/g, ' '));
  await blur(page);
  await mark(page, note, descSelect(row, 'MAIN JOB'));
  await r.snap(page, 2.4);
  await unmark(page);

  // Solusi: pilih indikator dari daftar (ketik untuk mencari), atau ikon + untuk membuat indikator baru.
  await waitNotificationsGone(page);
  await descSelect(row, 'MAIN JOB').click();
  await rec.settle(page, 400);
  await page.keyboard.type('tiket', { delay: 60 });
  await rec.settle(page, 500);
  const list = page.locator('[id^="fi-select-input-dropdown"]:visible').first();
  await mark(page, list, row.getByRole('button', { name: 'Buat', exact: true }));
  await r.snap(page, 1.5);
  await unmark(page);
  await page.locator('.fi-select-input-option:visible', { hasText: 'Tiket selesai per bulan' }).first().click();
  await rec.settle(page, 400);
  await blur(page);
  await markSnap(page, r, [row], 1.5);
}

// Error 3: Count Type RESULT without Value Plan -> the browser stops the submit on Value Plan (no request, no toast).
async function errorValuePlan(page, r) {
  const lw = trackLivewire(page);
  await prepareValidForm(page);
  const row = await addRow(page);
  await pickDescription(page, row, 'MAIN JOB', 'Tiket selesai per bulan');
  await blur(page);
  await scrollToY(row, 380);

  await markSnap(page, r, [countSelect(row, 'MAIN JOB')], 1.2);
  await setCountType(page, row, 'MAIN JOB', 'RESULT');
  await blur(page);
  const plan = planInput(row, 'MAIN JOB');
  console.log('value plan', JSON.stringify(await plan.evaluate((e) => ({ required: e.required, disabled: e.disabled, min: e.min }))));
  await markUnion(page, [countSelect(row, 'MAIN JOB'), plan], 6);
  await r.snap(page, 1.5);
  await unmark(page);

  const submit = submitButton(page);
  const before = lw.n;
  await mark(page, submit);
  await r.snap(page, 0.9);
  await unmark(page);
  await submit.click();
  await parkMouse(page);
  await page.waitForTimeout(600);
  console.log('requests after submit', lw.n - before, 'active', await page.evaluate(() => document.activeElement && document.activeElement.id),
    'message', await plan.evaluate((e) => e.validationMessage), 'toasts', await page.locator('.fi-no-notification').count());
  await mark(page, plan);
  await r.snap(page, 2.2);
  await unmark(page);

  // Solusi: Value Plan 40 (minimal 1).
  await plan.fill('40');
  await blur(page);
  await markSnap(page, r, [plan], 1.5);
}

// Error 4: percentages 60 + 20 + 10 -> "Validation Error" + an empty "Error occurred".
async function errorPercentageTotal(page, r) {
  await openCreate(page);
  await fillHeader(page);
  await percentageInput(page, 'MAIN JOB').fill('60');
  await fillRow(page, 'MAIN JOB', 'Tiket selesai per bulan', 'RESULT', 40);
  await switchTab(page, 'ADMINISTRATION');
  await percentageInput(page, 'ADMINISTRATION').fill('20');
  await fillRow(page, 'ADMINISTRATION', 'Timesheet lengkap', 'NON');
  await switchTab(page, 'REPORTING');
  await percentageInput(page, 'REPORTING').fill('10');
  await fillRow(page, 'REPORTING', 'Laporan bulanan tepat waktu', 'RESULT', 1);
  await switchTab(page, 'MAIN JOB');
  await blur(page);
  // Every frame at the bottom of the page: header values, the three tabs and the footer Buat all in view.
  await scrollToBottom(page.locator('.fi-sc-tabs').first());

  await markSnap(page, r, [tabButton(page, 'MAIN JOB'), percentageWrap(page, 'MAIN JOB')], 1.0);
  await switchTab(page, 'ADMINISTRATION');
  await markSnap(page, r, [tabButton(page, 'ADMINISTRATION'), percentageWrap(page, 'ADMINISTRATION')], 1.0);
  await switchTab(page, 'REPORTING');
  await markSnap(page, r, [tabButton(page, 'REPORTING'), percentageWrap(page, 'REPORTING')], 1.3);

  const submit = submitButton(page);
  await clickStep(page, r, submit, 0.9, { scroll: false });
  await waitNotification(page, 'Total percentage must be 100%');
  await page.locator('.fi-no-notification', { hasText: 'Error occurred' }).first().waitFor().catch(() => console.log('no "Error occurred" toast'));
  await page.waitForTimeout(500);
  await logToasts(page, 'toasts');
  await blur(page);
  await markUnion(page, [page.locator('.fi-no-notification').first(), page.locator('.fi-no-notification').last()], 4);
  await r.snap(page, 2.5);
  await unmark(page);

  // Solusi: REPORTING 20 (tidak disimpan, supaya tidak ada KPI ganda).
  await waitNotificationsGone(page);
  await percentageInput(page, 'REPORTING').fill('20');
  await blur(page);
  await markSnap(page, r, [tabButton(page, 'REPORTING'), percentageWrap(page, 'REPORTING')], 1.5);
}

// GIF 2 (SOP 2): sign in with WhatsApp OTP, then enter this month's review for a team member.
async function supervisorReviewFlow(page, r) {
  await go(page, '/admin/login', 600);
  const wa = page.getByRole('link', { name: 'WhatsApp' });
  await wa.evaluate((el) => { const over = el.getBoundingClientRect().bottom + 16 - window.innerHeight; if (over > 0) window.scrollBy(0, over); });
  await page.waitForTimeout(300);
  await clickStep(page, r, wa, 1.3, { scroll: false });
  await page.waitForURL(/\/phone-login$/, { waitUntil: 'commit' });
  await page.waitForFunction(() => window.Livewire && window.Alpine, null, { timeout: 30000 });
  await rec.settle(page, 600);

  const number = page.getByLabel('Nomor WhatsApp');
  await number.fill(ACTOR_PHONE);
  await blur(page);
  const send = page.getByRole('button', { name: 'Kirim OTP WhatsApp' });
  await mark(page, number.locator('xpath=ancestor::div[contains(@class,"fi-input-wrp")][1]'), send);
  await r.snap(page, 1.4);
  await unmark(page);
  const since = Date.now();
  await send.click();
  const otpInput = page.getByLabel('Kode OTP');
  await otpInput.waitFor();
  const otp = await rec.readOtp(ACTOR_PHONE, { since });
  await rec.settle(page, 300);
  await otpInput.fill(otp);
  await blur(page);
  const verify = page.getByRole('button', { name: 'Verifikasi dan Masuk' });
  console.log('nomor after send', await number.inputValue());
  await mark(page, otpInput.locator('xpath=ancestor::div[contains(@class,"fi-input-wrp")][1]'), verify);
  await r.snap(page, 1.5);
  await unmark(page);
  await verify.click();
  await page.waitForURL(/\/admin$/, { waitUntil: 'commit', timeout: 20000 });
  await rec.settle(page, 1200);
  // Collapsed sidebar (room for every column of the Penilaian table); hovering the icon shows its label.
  await setSidebar(page, false);

  // Menu Penilaian.
  const menu = page.locator('a[href$="/admin/employee-reviews"]').first();
  await menu.hover();
  await page.waitForTimeout(600);
  await clickStep(page, r, menu, 1.3, { scroll: false, settleMs: 300 });
  await page.waitForURL(/\/admin\/employee-reviews$/, { waitUntil: 'commit' });
  await rec.settle(page, 1200);
  await page.locator('tr.fi-ta-row').first().waitFor();
  await rec.settle(page, 400);

  await clickStep(page, r, page.getByRole('link', { name: /^Buat employee review$/i }), 1.2, { scroll: false });
  await page.waitForURL(/\/employee-reviews\/create$/, { waitUntil: 'commit' });
  await rec.settle(page, 1000);

  // Pengguna.
  await page.locator('#form\\.user_id').click();
  await rec.settle(page, 300);
  await page.keyboard.type(TARGET.reviewSearch, { delay: 60 });
  await rec.settle(page, 600);
  await clickStep(page, r, page.locator('.fi-select-input-option:visible', { hasText: TARGET.name }).first(), 1.2, { scroll: false });

  // Periode (bulan berjalan, otomatis terisi).
  const periode = page.locator('#form\\.periode');
  if ((await periode.inputValue()) !== REVIEW_PERIODE) await periode.fill(REVIEW_PERIODE);
  await blur(page);
  console.log('periode', await periode.inputValue());
  await markSnap(page, r, [inputWrap(page, 'form.periode')], 1.4);

  // Empat nilai 0-5.
  const scores = { responsiveness: 4, problem_solver: 4, helpfulness: 5, initiative: 4 };
  for (const [id, value] of Object.entries(scores)) await page.locator('#form\\.' + id).fill(String(value));
  await blur(page);
  await markUnion(page, Object.keys(scores).map((id) => field(page, 'form.' + id)), 6);
  await r.snap(page, 1.8);
  await unmark(page);

  await clickStep(page, r, submitButton(page), 1.0, { scroll: false });
  await page.waitForURL(/\/employee-reviews$/, { waitUntil: 'commit', timeout: 20000 });
  const note = await waitNotification(page, 'Data berhasil dibuat').catch(async (e) => { await logToasts(page, 'toasts'); throw e; });
  const created = page.locator('tr.fi-ta-row', { hasText: TARGET.name }).filter({ hasText: REVIEW_PERIODE }).first();
  await created.waitFor({ timeout: 20000 });
  await rec.settle(page, 300);
  console.log('toast', (await note.innerText()).replace(/\s+/g, ' '), '| row', (await created.innerText()).replace(/\s+/g, ' '));
  await parkMouse(page);
  await mark(page, note);
  await markUnion(page, [created], 0);
  await r.snap(page, 2.6);
  await unmark(page);
}

const flows = {
  'kpi-flow-lengkap': kpiFlowLengkap,
  'kpi-error-required-fields': errorRequiredFields,
  'kpi-error-kpi-description': errorKpiDescription,
  'kpi-error-value-plan': errorValuePlan,
  'kpi-error-percentage-total': errorPercentageTotal,
  'supervisor-review-flow': supervisorReviewFlow,
};
// The review GIF signs in on camera; the KPI GIFs sign in off camera.
const SIGNED_OUT = new Set(['supervisor-review-flow']);

(async () => {
  const args = process.argv.slice(2);
  if (args[0] === 'rebuild') {
    for (const gif of args.slice(1)) console.log(JSON.stringify(rec.buildGif(path.join(rec.framesDir(gif), 'list.txt'), path.join(OUT_DIR, gif + '.gif'))));
    return;
  }
  const keepDb = args.includes('--keep-db');
  const names = args.filter((a) => a !== '--keep-db');
  for (const name of names) if (!flows[name]) throw new Error(`unknown flow ${name} (use ${Object.keys(flows).join(', ')})`);
  for (const name of (names.length ? names : Object.keys(flows))) {
    if (!keepDb) rec.resetDb(GUIDE);
    const { browser, page } = await rec.open(BASE, { indonesianUi: true });
    try {
      if (!SIGNED_OUT.has(name)) await login(page, ACTOR);
      const r = new rec.Recorder(name);
      await flows[name](page, r);
      console.log(JSON.stringify(r.build(path.join(OUT_DIR, name + '.gif'))));
    } catch (e) {
      await page.screenshot({ path: rec.debugPath('fail-' + name + '.png') }).catch(() => {});
      throw e;
    } finally {
      await browser.close();
    }
  }
})().catch((e) => { console.error(e); process.exit(1); });
