// Records the GIFs for Panduan KPI Operasional (kpi-staff-guide.blade.php).
// Usage: node kpi-operasional.js [staff-login-flow] [staff-checklist-flow] [--keep-db]   (default: both)
//   node kpi-operasional.js rebuild <gif-name>...  rebuilds GIFs from the frames already recorded (no browser, no DB).
//
// Actor: demo_staf_1 (IFA PURWANTI, STAFF Kasir, no subordinates, fake WhatsApp number 0812-0000-0101). The KPI layer
// of demo_seed.php gives it a Kasir KPI set for the current month (not filled in yet) and a locked previous month.
// Server: sh serve.sh kpioperasional (port 8015, DB dnd_demo_kpioperasional, WhatsApp -> local stub on 8019).
const rec = require('../lib.js');
const path = require('path');

const GUIDE = 'kpioperasional';
const BASE = rec.baseUrl(GUIDE);
const OUT_DIR = rec.outDir(GUIDE);
const USERNAME = 'demo_staf_1';
const PHONE = '081200000101'; // fake demo number (users.no_hp of demo_staf_1 in the KPI layer)

// Error texts of PhoneLogin / InteractsWithWhatsAppLogin: the recording must never show one of them.
const OTP_ERRORS = [
  'Nomor WhatsApp tidak valid.',
  'No. HP belum terdaftar untuk login WhatsApp.',
  'OTP belum bisa dikirim ke WhatsApp. Coba lagi sebentar lagi.',
  'OTP tidak valid atau sudah kedaluwarsa.',
  'Terlalu banyak permintaan OTP. Coba lagi sebentar lagi.',
  'Terlalu banyak percobaan OTP. Coba lagi sebentar lagi.',
];

// page.goto without waiting for the 'load' event (external fonts/avatar can stall it).
async function go(page, url, ms = 800) {
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
  await rec.settle(page, ms);
}

async function waitLivewire(page) {
  await page.waitForFunction(() => window.Livewire && window.Alpine, null, { timeout: 30000 });
}

// Password login off camera, retried when the first submit does not navigate.
async function login(page, username) {
  for (let attempt = 1; ; attempt++) {
    try {
      await go(page, '/admin/login', 300);
      await waitLivewire(page);
      await page.locator('input[type=text]').first().fill(username);
      await page.locator('input[type=password]').first().fill(rec.PASSWORD);
      await Promise.all([
        page.waitForURL(/\/admin(\?.*)?$/, { waitUntil: 'commit', timeout: 10000 }),
        page.locator('button[type=submit]').first().click(),
      ]);
      await rec.settle(page);
      return;
    } catch (e) {
      await page.screenshot({ path: rec.debugPath('login-fail-kpioperasional-' + attempt + '.png') }).catch(() => {});
      console.log('login attempt', attempt, 'failed at', page.url(), e.message.split('\n')[0]);
      if (attempt >= 5) throw e;
    }
  }
}

// Move the mouse to an empty corner so no row/button keeps its hover style in the next frame.
async function parkMouse(page) {
  await page.mouse.move(1090, 610);
  await page.waitForTimeout(150);
}

// Scroll the panel's inner scroll container so the element's top sits at viewport y.
async function scrollToY(locator, y) {
  await locator.evaluate((el, y) => {
    let p = el.parentElement;
    while (p && !(['auto', 'scroll'].includes(getComputedStyle(p).overflowY) && p.scrollHeight > p.clientHeight)) p = p.parentElement;
    (p || document.scrollingElement).scrollTop += el.getBoundingClientRect().top - y;
  }, y);
  await locator.page().waitForTimeout(300);
}

// Scroll the panel's inner scroll container to its end.
async function scrollToBottom(locator) {
  await locator.evaluate((el) => {
    let p = el.parentElement;
    while (p && !(['auto', 'scroll'].includes(getComputedStyle(p).overflowY) && p.scrollHeight > p.clientHeight)) p = p.parentElement;
    const box = p || document.scrollingElement;
    box.scrollTop = box.scrollHeight;
  });
  await locator.page().waitForTimeout(300);
}

const MARK_STYLE = { border: '3px solid #ef4444', borderRadius: '8px', zIndex: 2147483647, pointerEvents: 'none', boxSizing: 'border-box' };

// Red box drawn over each element as a fixed overlay (Filament input wrappers clip outline-offset).
async function mark(page, ...locators) {
  for (const locator of locators) {
    await locator.evaluate((el, style) => {
      const r = el.getBoundingClientRect();
      const box = document.createElement('div');
      box.className = 'rec-mark';
      Object.assign(box.style, style, { position: 'fixed', left: (r.left - 4) + 'px', top: (r.top - 4) + 'px', width: (r.width + 8) + 'px', height: (r.height + 8) + 'px' });
      document.body.appendChild(box);
    }, MARK_STYLE);
  }
}

// One red box around several elements. With `content`, each element counts only with its visible content (the text
// and pill inside a table cell, not the whole cell height).
async function markUnion(page, locators, pad = 4, { content = false } = {}) {
  const rects = [];
  for (const locator of locators) {
    rects.push(await locator.evaluate((el, content) => {
      let list = [el.getBoundingClientRect()];
      if (content) {
        const range = document.createRange();
        range.selectNodeContents(el);
        const inner = [...range.getClientRects()].filter((x) => x.width > 0 && x.height > 0);
        if (inner.length) list = inner;
      }
      return [Math.min(...list.map((x) => x.left)), Math.min(...list.map((x) => x.top)), Math.max(...list.map((x) => x.right)), Math.max(...list.map((x) => x.bottom))];
    }, content));
  }
  const box = [Math.min(...rects.map((x) => x[0])) - pad, Math.min(...rects.map((x) => x[1])) - pad, Math.max(...rects.map((x) => x[2])) + pad, Math.max(...rects.map((x) => x[3])) + pad];
  await page.evaluate(([[l, t, r, b], style]) => {
    const el = document.createElement('div');
    el.className = 'rec-mark';
    Object.assign(el.style, style, { position: 'fixed', left: l + 'px', top: t + 'px', width: (r - l) + 'px', height: (b - t) + 'px' });
    document.body.appendChild(el);
  }, [box, MARK_STYLE]);
}

async function unmark(page) {
  await page.evaluate(() => document.querySelectorAll('.rec-mark').forEach((el) => el.remove()));
}

// Mark, capture, unmark, click, settle.
async function clickStep(page, r, locator, seconds = 1.2) {
  await locator.evaluate((el) => el.scrollIntoView({ block: 'nearest', inline: 'nearest' }));
  await page.waitForTimeout(200);
  await mark(page, locator);
  await r.snap(page, seconds);
  await unmark(page);
  await locator.click();
  await rec.settle(page);
}

async function assertNoOtpError(page) {
  const body = await page.locator('body').innerText();
  const hit = OTP_ERRORS.find((t) => body.includes(t));
  if (hit) {
    await page.screenshot({ path: rec.debugPath('otp-error-kpioperasional.png') }).catch(() => {});
    throw new Error('phone login shows an error: ' + hit);
  }
}

// The Filament input wrapper (border box) around an input.
function inputWrap(locator) {
  return locator.locator('xpath=ancestor::div[contains(@class,"fi-input-wrp")][1]');
}

// Card in the free area right of the login card that shows the WhatsApp message the demo account received
// (text taken from the local stub, so it is exactly what the app sent).
async function showWaMessage(page, text, code) {
  await page.evaluate(([text, code]) => {
    const card = document.createElement('div');
    card.className = 'rec-wa';
    Object.assign(card.style, {
      position: 'fixed', left: '772px', top: '318px', width: '308px', zIndex: 2147483646, pointerEvents: 'none',
      background: '#ffffff', border: '1px solid #e5e7eb', borderRadius: '14px', boxShadow: '0 10px 30px rgba(0,0,0,.12)',
      fontFamily: 'inherit', color: '#111827', overflow: 'hidden',
    });
    const head = document.createElement('div');
    Object.assign(head.style, { display: 'flex', alignItems: 'center', gap: '8px', padding: '10px 14px', background: '#f0fdf4', borderBottom: '1px solid #dcfce7', fontSize: '12px', fontWeight: '600', color: '#166534' });
    const dot = document.createElement('span');
    Object.assign(dot.style, { width: '10px', height: '10px', borderRadius: '9999px', background: '#22c55e', display: 'inline-block' });
    head.append(dot, document.createTextNode('Pesan WhatsApp di HP Anda (contoh)'));
    const body = document.createElement('div');
    Object.assign(body.style, { padding: '12px 14px 14px', fontSize: '13px', lineHeight: '1.5' });
    const bubble = document.createElement('div');
    Object.assign(bubble.style, { background: '#f9fafb', border: '1px solid #f3f4f6', borderRadius: '10px', padding: '10px 12px' });
    const [before, after] = text.split(code);
    const strong = document.createElement('strong');
    strong.textContent = code;
    Object.assign(strong.style, { letterSpacing: '1px', color: '#111827' });
    bubble.append(document.createTextNode(before), strong, document.createTextNode(after ?? ''));
    body.append(bubble);
    card.append(head, body);
    document.body.appendChild(card);
  }, [text, code]);
}

async function hideWaMessage(page) {
  await page.evaluate(() => document.querySelectorAll('.rec-wa').forEach((el) => el.remove()));
}

// GIF 1 (SOP 1): the login page offers Username + Kata sandi and "Atau masuk dengan" WhatsApp; the GIF follows the
// WhatsApp OTP path to Dasbor, where the Checklist KPI tab is already active.
async function staffLoginFlow(page, r) {
  await go(page, '/admin/login', 600);
  await waitLivewire(page);
  // Logo + auth card are a little taller than the 618 px viewport: scroll the window just enough to show the whole
  // card (down to the WhatsApp button) while the logo stays fully visible.
  const wa = page.getByRole('link', { name: 'WhatsApp' });
  await wa.waitFor();
  await wa.evaluate((a) => {
    const logo = document.querySelector('img');
    const card = a.closest('.mky-card') || a;
    const logoTop = logo ? logo.getBoundingClientRect().top : Infinity;
    const need = Math.max(a.getBoundingClientRect().bottom + 6, card.getBoundingClientRect().bottom + 1) - innerHeight;
    window.scrollTo(0, Math.max(0, Math.min(logoTop - 1, need)));
  });
  await page.waitForTimeout(300);
  await parkMouse(page);

  // Frame 1: both ways to log in on one page, nothing typed.
  await r.snap(page, 1.6);

  // Frame 2: "Atau masuk dengan" > WhatsApp.
  await clickStep(page, r, wa, 1.2);
  await page.waitForURL(/\/phone-login$/);
  await waitLivewire(page);
  await rec.settle(page, 500);

  // Frame 3: Masuk dengan WhatsApp > Nomor WhatsApp.
  const number = page.getByLabel('Nomor WhatsApp');
  await number.waitFor();
  await mark(page, inputWrap(number));
  await r.snap(page, 1.2);
  await unmark(page);

  // Frame 4: number filled in, Kirim OTP WhatsApp.
  await number.fill(PHONE);
  await page.waitForTimeout(200);
  const since = Date.now();
  await clickStep(page, r, page.getByRole('button', { name: 'Kirim OTP WhatsApp' }), 1.3);
  await parkMouse(page);
  const otpInput = page.getByLabel('Kode OTP');
  await otpInput.waitFor({ state: 'visible' }).catch(async (e) => { await assertNoOtpError(page); throw e; });
  await assertNoOtpError(page);
  const otp = await rec.readOtp(PHONE, { since });
  const msg = await rec.lastWaMessage(PHONE, since);
  if (!msg || msg.recipient.replace(/\D/g, '').replace(/^62/, '0') !== PHONE) throw new Error('OTP went to an unexpected recipient');
  await rec.settle(page, 400);

  // Frame 5: OTP stage (number shown as 62…, Kode OTP field), with the WhatsApp message the demo account received.
  await showWaMessage(page, msg.text, otp);
  await mark(page, inputWrap(otpInput));
  await r.snap(page, 2.0);
  await unmark(page);

  // Frame 6: code filled in, Verifikasi dan Masuk.
  await otpInput.fill(otp);
  await otpInput.blur();
  await page.waitForTimeout(200);
  const verify = page.getByRole('button', { name: 'Verifikasi dan Masuk' });
  await mark(page, verify);
  await r.snap(page, 1.3);
  await unmark(page);
  await hideWaMessage(page);
  await Promise.all([page.waitForURL(/\/admin(\?.*)?$/, { waitUntil: 'commit', timeout: 20000 }), verify.click()]);
  await rec.settle(page, 900);
  await parkMouse(page);
  await page.locator('#checklist-month').waitFor();

  // Frame 7: Dasbor with the staff menu.
  await r.snap(page, 1.4);

  // Frame 8: the Checklist KPI tab is already active.
  await mark(page, page.getByRole('button', { name: 'Checklist KPI' }));
  await r.snap(page, 1.4);
  await unmark(page);

  // Frame 9: the checklist of the current month (card heading, month filter, month and deadline). No scroll: with the
  // sidebar expanded the table below is narrow, and the heading area is what this SOP ends on.
  const heading = page.locator('h2', { hasText: 'Checklist' }).first();
  const monthRow = page.locator('h3', { hasText: monthLabel() }).first().locator('xpath=..');
  await markUnion(page, [heading, page.locator('#checklist-month'), monthRow], 6);
  await r.snap(page, 2.4);
  await unmark(page);
}

// Current month as YYYY-MM by the computer clock (the KPI layer seeds the current month the same way).
function currentMonth() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

// Previous month as YYYY-MM (the KPI layer seeds it filled in and locked).
function previousMonth() {
  const d = new Date();
  const p = new Date(d.getFullYear(), d.getMonth() - 1, 1);
  return `${p.getFullYear()}-${String(p.getMonth() + 1).padStart(2, '0')}`;
}

// Month heading of the checklist widget (Carbon format('F Y'), English): e.g. "October 2026"; offset -1 = previous month.
function monthLabel(offset = 0) {
  const n = new Date();
  const d = new Date(n.getFullYear(), n.getMonth() + offset, 1);
  return d.toLocaleString('en-US', { month: 'long' }) + ' ' + d.getFullYear();
}

const kpiRow = (page, text) => page.locator('tr', { hasText: text }).first();
const cell = (row, i) => row.locator('td').nth(i);

async function waitCell(page, rowText, i, expected) {
  await page.waitForFunction(([t, i, e]) => {
    const row = [...document.querySelectorAll('tr')].find((tr) => tr.innerText.includes(t));
    return row && row.querySelectorAll('td')[i] && row.querySelectorAll('td')[i].innerText.trim() === e;
  }, [rowText, i, expected], { timeout: 15000 });
  await rec.settle(page, 300);
}

const NON_MAIN = 'Menjalankan SOP buka dan tutup kasir';
const RES_POS = 'Jumlah transaksi member baru';
const RES_NEG = 'Selisih kas akhir shift';
const NON_ADMIN = 'Input penjualan harian ke sistem';
const NON_REPORT = 'Kirim laporan stok mingguan ke Kepala Toko';

// Opens Update KPI on a RESULT row, enters the actual value and saves (frames: icon, modal, Simpan, result).
async function updateResult(page, r, rowText, actual, expected, { negative = false } = {}) {
  const row = kpiRow(page, rowText);
  await clickStep(page, r, row.getByRole('button', { name: 'Update KPI Result' }), negative ? 1.0 : 1.2);
  const modal = page.locator('.fi-modal-window', { hasText: 'Perbarui nilai actual untuk KPI ini' });
  await modal.waitFor({ state: 'visible' });
  await modal.locator('#update_value_actual').waitFor({ state: 'visible' });
  await page.waitForTimeout(500);
  const input = modal.locator('#update_value_actual');
  const save = modal.getByRole('button', { name: 'Simpan' });
  if (!negative) {
    // Modal: description, RESULT + month, Plan / Actual / Result, Value Actual Baru (pre-filled 0).
    await input.evaluate((el) => el.blur());
    await page.waitForTimeout(200);
    await mark(page, inputWrap(input));
    await r.snap(page, 1.5);
    await unmark(page);
    await input.fill(String(actual));
    await input.evaluate((el) => el.blur());
    await page.waitForTimeout(200);
    await mark(page, save);
    await r.snap(page, 1.2);
  } else {
    // Negative KPI: the "Negatif" badge and the plan / actual hint; actual filled in, then Simpan.
    await input.fill(String(actual));
    await input.evaluate((el) => el.blur());
    await page.waitForTimeout(200);
    await mark(page, modal.getByText('Negatif', { exact: true }), save);
    await r.snap(page, 1.8);
  }
  await unmark(page);
  await save.click();
  await modal.waitFor({ state: 'hidden' });
  await waitCell(page, rowText, 7, expected);
  await parkMouse(page);
  const after = kpiRow(page, rowText);
  await markUnion(page, [cell(after, 6), cell(after, 7)], 10, { content: true });
  await r.snap(page, negative ? 1.3 : 1.8);
  await unmark(page);
}

// Toggle a NON row (red check-circle) and wait for 100%.
async function toggleNon(page, r, rowText, seconds = 1.0) {
  await clickStep(page, r, kpiRow(page, rowText).getByRole('button', { name: 'Toggle KPI Status' }), seconds);
  await waitCell(page, rowText, 7, '100%');
  await parkMouse(page);
}

// GIF 2 (SOP 2): the month filter (current month, the locked previous month, back), tick the NON rows, fill the actual of a positive and a negative RESULT KPI, and end
// on the Total Score (MAIN JOB 60% x avg(1, 0.80, 0.75) + ADMINISTRATION 20 + REPORTING 20 = 91.00 points).
async function staffChecklistFlow(page, r) {
  await go(page, '/admin?tab=checklist', 900);
  await waitLivewire(page);
  // Collapsed sidebar: the checklist table (up to ACTION) fits the 1100 px viewport.
  const toggle = page.locator('button[aria-controls="mekaya-desktop-sidebar"]');
  if ((await toggle.getAttribute('aria-expanded')) !== 'false') { await toggle.click(); await rec.settle(page, 500); }
  await parkMouse(page);
  const month = page.locator('#checklist-month');
  await month.waitFor();
  const value = await month.inputValue();
  if (value !== currentMonth()) throw new Error(`month filter starts on "${value}", expected ${currentMonth()}`);
  const monthRow = (offset) => page.locator('h3', { hasText: monthLabel(offset) }).first().locator('xpath=..');

  // Frame 1: the month filter opens on the current month (Oktober 2026).
  await mark(page, inputWrap(month));
  await r.snap(page, 1.4);
  await unmark(page);

  // Frame 2: previous month picked: closed ("Ditutup sejak ..."), the status icons are grey locks.
  await month.fill(previousMonth());
  await page.locator('h3', { hasText: monthLabel(-1) }).first().waitFor();
  await rec.settle(page, 600);
  await page.evaluate(() => document.activeElement && document.activeElement.blur());
  await page.waitForTimeout(300);
  const locks = page.getByRole('button', { name: 'Periode checklist sudah ditutup' });
  if ((await locks.count()) === 0) throw new Error('previous month is not locked');
  await mark(page, inputWrap(month));
  await markUnion(page, [monthRow(-1)], 6);
  const firstLock = locks.first();
  const lockBox = await firstLock.boundingBox();
  if (lockBox && lockBox.y + lockBox.height < 610) await mark(page, firstLock);
  await r.snap(page, 2.0);
  await unmark(page);

  // Frame 3: back to the current month: open, with the deadline badge ("Batas pengisian ...").
  await month.fill(currentMonth());
  await page.locator('h3', { hasText: monthLabel() }).first().waitFor();
  await rec.settle(page, 600);
  await page.evaluate(() => document.activeElement && document.activeElement.blur());
  await page.waitForTimeout(300);
  await mark(page, inputWrap(month));
  await markUnion(page, [monthRow(0)], 6);
  await r.snap(page, 1.6);
  await unmark(page);

  // Frames 4-5: NON row in MAIN JOB: red check-circle -> green, Result 100%.
  const mainJob = page.locator('h4', { hasText: 'MAIN JOB' }).first();
  await scrollToY(mainJob, 92);
  await parkMouse(page);
  await toggleNon(page, r, NON_MAIN, 1.2);
  const nonRow = kpiRow(page, NON_MAIN);
  await mark(page, cell(nonRow, 0).getByRole('button', { name: 'Toggle KPI Status' }), cell(nonRow, 7).locator('span').first());
  await r.snap(page, 1.3);
  await unmark(page);

  // Frames 6-9: positive RESULT, actual 32 of plan 40 -> 80.00% (status turns green, Extra Task icon appears).
  await updateResult(page, r, RES_POS, 32, '80.00%');
  // Frames 10-12: negative RESULT, actual 4 of plan 3 -> 75.00%.
  await updateResult(page, r, RES_NEG, 4, '75.00%', { negative: true });

  // Frames 13-14: NON rows of ADMINISTRATION and REPORTING.
  await scrollToY(page.locator('h4', { hasText: 'ADMINISTRATION' }).first(), 92);
  await parkMouse(page);
  await toggleNon(page, r, NON_ADMIN, 1.0);
  await toggleNon(page, r, NON_REPORT, 1.0);

  // Frame 15: Total Score of the month.
  const total = page.locator('span', { hasText: 'Total' }).filter({ hasText: 'Score' }).first();
  await scrollToBottom(total);
  await parkMouse(page);
  const totalCard = total.locator('xpath=ancestor::div[contains(@class,"rounded-xl")][1]');
  console.log('total', (await totalCard.innerText()).replace(/\s+/g, ' '));
  await mark(page, totalCard);
  await r.snap(page, 2.4);
  await unmark(page);
}

const flows = {
  'staff-login-flow': { run: staffLoginFlow, login: false },
  'staff-checklist-flow': { run: staffChecklistFlow, login: true },
};

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
      if (flows[name].login) await login(page, USERNAME);
      const r = new rec.Recorder(name);
      await flows[name].run(page, r);
      console.log(JSON.stringify(r.build(path.join(OUT_DIR, name + '.gif'))));
    } finally {
      await browser.close();
    }
  }
})().catch((e) => { console.error(e); process.exit(1); });
