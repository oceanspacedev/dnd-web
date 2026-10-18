// Records the GIFs for Panduan Aturan Penilaian. Usage: node aturan.js [gif-name ...] [--keep-db]   (default: all)
//   node aturan.js rebuild <gif-name>...  rebuilds GIFs from the frames already recorded (no browser, no DB).
const rec = require('../lib.js');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = rec.baseUrl('aturan');
const SIM_Y = parseInt(process.env.SIM_Y || '190', 10);
const OUT_DIR = rec.outDir('aturan');

function resetDb() {
  rec.resetDb('aturan');
}

// page.goto/login without waiting for the 'load' event (external fonts/avatar can stall it).
async function go(page, url, ms = 800) {
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
  await rec.settle(page, ms);
}

async function login(page, username) {
  for (let attempt = 1; ; attempt++) {
    try {
      await go(page, '/admin/login', 300);
      await page.waitForFunction(() => window.Livewire && window.Alpine, null, { timeout: 30000 });
      await page.locator('input[type=text]').first().fill(username);
      await page.locator('input[type=password]').first().fill(rec.PASSWORD);
      await Promise.all([
        page.waitForURL(/\/admin(\?.*)?$/, { waitUntil: 'commit', timeout: 10000 }),
        page.locator('button[type=submit]').first().click(),
      ]);
      await rec.settle(page);
      return;
    } catch (e) {
      await page.screenshot({ path: rec.debugPath('login-fail-aturan-' + attempt + '.png') }).catch(() => {});
      console.log('login attempt', attempt, 'failed at', page.url(), e.message.split('\n')[0]);
      if (attempt >= 5) throw e;
    }
  }
}

// Scroll an element to the vertical centre of the inner scroll container.
async function center(locator, block = 'center') {
  await locator.evaluate((el, b) => el.scrollIntoView({ block: b, inline: 'nearest' }), block);
  await locator.page().waitForTimeout(250);
}

// Scroll the inner scroll container so the element's top sits at viewport y.
async function scrollToY(locator, y) {
  await locator.evaluate((el, y) => {
    let p = el.parentElement;
    while (p && !(['auto', 'scroll'].includes(getComputedStyle(p).overflowY) && p.scrollHeight > p.clientHeight)) p = p.parentElement;
    if (p) p.scrollTop += el.getBoundingClientRect().top - y;
  }, y);
  await locator.page().waitForTimeout(300);
}

// Red box drawn over the element(s) as a fixed overlay (Filament inputs/buttons clip or reset outlines).
async function mark(page, ...locators) {
  for (const locator of locators) {
    await locator.evaluate((el) => {
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
async function markUnion(page, locators, pad = -2) {
  const rects = [];
  // Clip each rect to its horizontally scrolling ancestor (wide tables).
  for (const locator of locators) rects.push(await locator.evaluate((el) => {
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

// Mark, capture, unmark, click, settle.
async function clickStep(page, r, locator, seconds = 1.2, opts = {}) {
  await locator.evaluate((el) => el.scrollIntoView({ block: 'nearest', inline: 'nearest' }));
  await page.waitForTimeout(200);
  await mark(page, locator);
  await r.snap(page, seconds);
  await unmark(page);
  await locator.click(opts);
  await rec.settle(page);
}

// Filament field wrapper (label + input + hint/helper) for an input id.
function field(page, id) {
  return page.locator('.fi-fo-field').filter({ has: page.locator('[id="' + id + '"]') }).last();
}

// GIF 1 (SOP 1): Periode defaults to last month; components that month's rule does not use are greyed out and the
// sick field is "Sakit/Izin" for periodes before izin got its own component. The demo seed has attendance for last
// month (2026-09) for every staff member, so the team leader (no attendance yet) is the one filled in.
const FORM_EMPLOYEE = { search: 'yunita', name: 'YUNITA USADA' };

async function kehadiranIsiForm(page, r) {
  await go(page, '/admin/attendances');
  await clickStep(page, r, page.getByRole('link', { name: 'Buat Kehadiran' }), 1.2);
  await rec.settle(page, 600);

  // Periode sudah terisi bulan lalu, dengan versi aturan periode itu di bawahnya.
  console.log('periode default', await page.locator('#form\\.periode').inputValue());
  await mark(page, field(page, 'form.periode'));
  await r.snap(page, 1.8);
  await unmark(page);

  // Pengguna
  await page.locator('#form\\.user_id').click(); await rec.settle(page, 300);
  await page.keyboard.type(FORM_EMPLOYEE.search, { delay: 60 }); await rec.settle(page, 500);
  await clickStep(page, r, page.locator('.fi-select-input-option', { hasText: FORM_EMPLOYEE.name }).first(), 1.2);

  // Hari Kerja 22, Terlambat <= 30 menit 1, Sakit/Izin 2 (sakit + izin).
  await page.locator('#form\\.work_days').click(); await rec.settle(page, 400);
  await page.locator('#form\\.work_days').fill('22');
  await page.locator('#form\\.late_less_30').fill('1');
  await page.locator('#form\\.sick_days').fill('2');
  await page.locator('#form\\.sick_days').blur(); await rec.settle(page, 400);
  await center(page.locator('#form\\.sick_days'));
  await mark(page, field(page, 'form.work_days').locator('.fi-input-wrp'), field(page, 'form.late_less_30').locator('.fi-input-wrp'), field(page, 'form.sick_days'));
  await r.snap(page, 1.8);
  await unmark(page);

  // Isian yang tidak dipakai aturan periode itu: abu-abu, dengan tulisan "Tidak dipakai di aturan periode ini".
  const unused = ['izin', 'mangkir', 'cuti_tahunan', 'cuti_lainnya'].map((code) => field(page, 'form.component_counts.' + code));
  console.log('izin disabled', await page.locator('#form\\.component_counts\\.izin').isDisabled());
  await center(page.locator('#form\\.component_counts\\.izin'));
  await markUnion(page, unused, 4);
  await r.snap(page, 2.4);
  await unmark(page);

  await clickStep(page, r, page.getByRole('button', { name: 'Buat', exact: true }), 1.2);
  await rec.settle(page, 1200);
  const row = page.locator('tr', { hasText: FORM_EMPLOYEE.name }).first();
  await row.waitFor();
  // Tampilkan kolom Skor Kehadiran dan rinciannya (tooltip).
  const scoreHeader = page.locator('th', { hasText: 'Skor Kehadiran' }).first();
  await scoreHeader.evaluate((el) => el.scrollIntoView({ inline: 'end', block: 'nearest' }));
  await page.waitForTimeout(300);
  const scoreCell = row.locator('td').nth(-2);
  console.log('score', await scoreCell.innerText());
  await scoreCell.locator('.fi-ta-text-item, span, div').first().hover();
  await page.waitForTimeout(700);
  await mark(page, scoreCell);
  await r.snap(page, 2.5);
  await unmark(page);
}


// Filled attendance import file for last month, generated from the app's own template by aturan-template.php after
// the DB reset (the employees without last-month attendance in the demo seed).
const IMPORT_FILE = path.join(rec.WORK_DIR, 'kehadiran-import.xlsx');
const IMPORT_EMPLOYEES = ['GARDA SITOMPUL', 'YUNITA USADA'];

function buildImportFile() {
  execFileSync('php', ['-d', 'memory_limit=1G', path.join(__dirname, 'aturan-template.php'), IMPORT_FILE], {
    stdio: 'inherit', env: { ...process.env, ...rec.SAFE_ENV, DB_DATABASE: 'dnd_demo_aturan', CACHE_PREFIX: 'demo_aturan_' },
  });
}

// GIF 2 (SOP 2): Download Template asks for the Periode (last month by default), the Import modal asks for the
// Periode yang diimport (last month by default), and the notification names that periode.
async function kehadiranImport(page, r) {
  await go(page, '/admin/attendances');

  // Langkah 1: Download Template > Periode (bulan lalu). Template tidak diunduh saat rekaman (file dibuat terpisah).
  await clickStep(page, r, page.getByRole('button', { name: 'Download Template' }), 1.1);
  const templateModal = page.locator('.fi-modal-window').filter({ hasText: 'Download Template Kehadiran' });
  await templateModal.waitFor();
  await rec.settle(page, 600);
  const templatePeriode = templateModal.locator('.fi-fo-field').filter({ hasText: 'Periode' }).first();
  console.log('template periode', await templatePeriode.locator('input').inputValue());
  // The month input is autofocused (blue month segment): blur it so the frame shows the plain value.
  await templatePeriode.locator('input').evaluate((el) => el.blur());
  await rec.settle(page, 500);
  await mark(page, templatePeriode, templateModal.getByRole('button', { name: 'Download', exact: true }));
  await r.snap(page, 2.0);
  await unmark(page);
  await templateModal.getByRole('button', { name: 'Batal', exact: true }).click();
  await rec.settle(page, 600);

  await clickStep(page, r, page.getByRole('button', { name: 'Import/Export' }), 1.0);
  await page.waitForTimeout(300);
  await clickStep(page, r, page.getByRole('button', { name: 'Import', exact: true }), 1.0);
  await rec.settle(page, 600);

  const modal = page.locator('.fi-modal-window').filter({ hasText: 'Import Data Absensi' });
  await modal.waitFor();
  const importPeriode = modal.locator('.fi-fo-field').filter({ hasText: 'Periode yang diimport' }).first();
  console.log('import periode', await importPeriode.locator('input').inputValue());
  await importPeriode.locator('input').evaluate((el) => el.blur());
  await rec.settle(page, 500);
  await modal.locator('input[type=file]').setInputFiles(IMPORT_FILE);
  await page.locator('.filepond--item[data-filepond-item-state="processing-complete"]').waitFor({ timeout: 30000 });
  await rec.settle(page, 600);
  await mark(page, importPeriode, modal.locator('.filepond--root'));
  await r.snap(page, 2.2);
  await unmark(page);

  await clickStep(page, r, modal.getByRole('button', { name: 'Import', exact: true }), 1.0);
  const note = page.locator('.fi-no-notification').filter({ hasText: 'Import Kehadiran' }).first();
  await note.waitFor({ timeout: 30000 });
  await rec.settle(page, 900);
  console.log('notification:', await note.innerText());
  await mark(page, note);
  await markUnion(page, IMPORT_EMPLOYEES.map((n) => page.locator('tr', { hasText: n }).first()));
  await r.snap(page, 2.8);
  await unmark(page);
}

async function aturanVersiBaru(page, r) {
  await go(page, '/admin/scoring-rules');
  const row = page.locator('tr', { hasText: 'Aturan Kehadiran November 2026' }).first();
  await markUnion(page, [row.locator('td').nth(0), row.locator('td').nth(2)]);
  await r.snap(page, 1.3);
  await unmark(page);

  // Ikon titik tiga (⋮) di ujung kanan baris.
  const more = row.getByRole('button', { name: 'Tindakan' });
  await more.evaluate((el) => el.scrollIntoView({ inline: 'end', block: 'nearest' }));
  await page.waitForTimeout(300);
  await clickStep(page, r, more, 1.0);
  await page.waitForTimeout(300);
  const dup = row.getByRole('link', { name: 'Duplikat jadi versi baru' });
  await mark(page, dup);
  await r.snap(page, 1.2);
  await unmark(page);
  await dup.click();
  await page.locator('#form\\.name').waitFor();
  await rec.settle(page, 800);

  // Form terisi dari versi sumber; Berlaku Mulai = bulan kosong berikutnya.
  console.log('prefill', await page.locator('#form\\.name').inputValue(), await page.locator('#form\\.effective_from').inputValue());
  await page.locator('#form\\.name').fill('Aturan Kehadiran Desember 2026');
  await page.locator('#form\\.notes').fill('Penalti terlambat > 30 menit turun dari 3 menjadi 2 poin/kejadian.');
  await page.locator('#form\\.notes').blur();
  await rec.settle(page, 300);
  await mark(page, field(page, 'form.name').locator('.fi-input-wrp'), field(page, 'form.effective_from'), field(page, 'form.notes').locator('textarea'));
  await r.snap(page, 1.7);
  await unmark(page);

  // Penalti Terlambat > 30 menit 3 -> 2 (komponen ini dinilai sama di versi lama dan versi November).
  const lateRow = page.locator('tr', { hasText: 'Terlambat > 30 menit' }).first();
  const latePenalty = lateRow.locator('input[type=number]');
  await scrollToY(lateRow, 260);
  await latePenalty.fill('2');
  await latePenalty.blur();
  await rec.settle(page, 900);
  await mark(page, lateRow);
  await r.snap(page, 1.5);
  await unmark(page);

  // Simulasi: 1 kali terlambat > 30 menit, bandingkan dengan versi yang berlaku bulan ini.
  const simLate = page.locator('#form\\.simulation\\.counts\\.late_more_30');
  await simLate.fill('1');
  await simLate.blur();
  await rec.settle(page, 1200);
  await scrollToY(field(page, 'form.simulation.counts.late_more_30'), SIM_Y);
  const draft = page.locator('fieldset', { hasText: 'Versi ini (belum disimpan)' }).first();
  const current = page.locator('fieldset', { hasText: 'Berlaku bulan ini' }).first();
  console.log('draft', (await draft.innerText()).replace(/\s+/g, ' '));
  console.log('current', (await current.innerText()).replace(/\s+/g, ' '));
  await mark(page, field(page, 'form.simulation.counts.late_more_30').locator('.fi-input-wrp'));
  await r.snap(page, 1.2);
  await unmark(page);

  // Hasil simulasi lengkap: versi baru (penalti 2) vs versi yang berlaku bulan ini (penalti 3), sampai Total.
  const submit = page.getByRole('button', { name: 'Buat', exact: true });
  await submit.evaluate((el) => el.scrollIntoView({ block: 'nearest', inline: 'nearest' }));
  await page.waitForTimeout(300);
  for (const which of ['draft', 'current']) {
    await markUnion(page, [page.locator(`[wire\\:partial$="_${which}_penalty"]`), page.locator(`[wire\\:partial$="_${which}_attendance_score"]`)], 8);
    await markUnion(page, [page.locator(`[wire\\:partial$="_${which}_total"]`)], 8);
  }
  await r.snap(page, 1.8);
  await unmark(page);

  await clickStep(page, r, page.getByRole('button', { name: 'Buat', exact: true }), 1.0);
  await page.waitForURL(/scoring-rules$/, { waitUntil: 'commit' });
  await rec.settle(page, 1000);
  const newRow = page.locator('tr', { hasText: 'Aturan Kehadiran Desember 2026' }).first();
  await newRow.waitFor();
  console.log('new row', (await newRow.innerText()).replace(/\s+/g, ' '));
  await markUnion(page, [newRow.locator('td').nth(0), newRow.locator('td').nth(2)]);
  await r.snap(page, 2.5);
  await unmark(page);
}

async function komponenTambah(page, r) {
  await go(page, '/admin/attendance-components');
  await clickStep(page, r, page.getByRole('button', { name: 'Tambah Komponen' }), 1.2);
  const panel = page.locator('.fi-modal-window').filter({ has: page.locator('[id$="code"]') }).first();
  await panel.waitFor();
  await rec.settle(page, 700);
  const ids = await panel.evaluate((el) => [...el.querySelectorAll('input, select, textarea, button')].map((e) => `${e.tagName} id=${e.id} type=${e.type} txt=${(e.innerText || '').trim().slice(0, 30)}`));
  console.log(ids.join('\n'));

  const code = panel.locator('input[id$=".code"]');
  const name = panel.locator('input[id$=".name"]');
  await code.fill('pulang_cepat');
  await name.fill('Pulang cepat');
  await panel.locator('textarea[id$=".description"]').fill('Pulang sebelum jam kerja selesai tanpa izin atasan.');
  await rec.settle(page, 300);
  await mark(page, code.locator('xpath=ancestor::div[contains(@class,"fi-input-wrp")][1]'), name.locator('xpath=ancestor::div[contains(@class,"fi-input-wrp")][1]'));
  await r.snap(page, 1.5);
  await unmark(page);

  const unit = panel.locator('select[id$=".unit"]');
  if (await unit.count()) {
    await unit.selectOption({ label: 'Kejadian' });
  } else {
    await panel.locator('[id$=".unit"]').click();
    await page.locator('.fi-select-input-option', { hasText: 'Kejadian' }).first().click();
  }
  await rec.settle(page, 400);
  await mark(page, panel.locator('.fi-fo-field').filter({ hasText: 'Satuan' }).first());
  await r.snap(page, 1.3);
  await unmark(page);

  // Keterangan, Urutan, dan Aktif (menyala) di bagian bawah panel, lalu Buat.
  const submit = panel.getByRole('button', { name: 'Buat', exact: true });
  const active = panel.locator('[id$=".is_active"]');
  await panel.locator('textarea[id$=".description"]').blur();
  await active.evaluate((el) => {
    let p = el.parentElement;
    while (p && !(['auto', 'scroll'].includes(getComputedStyle(p).overflowY) && p.scrollHeight > p.clientHeight)) p = p.parentElement;
    if (p) p.scrollTop = p.scrollHeight;
  });
  await page.waitForTimeout(300);
  await mark(page, active.locator('xpath=ancestor::div[contains(@class,"fi-fo-field")][1]'), submit);
  await r.snap(page, 1.5);
  await unmark(page);
  await submit.click();
  await rec.settle(page);
  const newRow = page.locator('tr', { hasText: 'pulang_cepat' }).first();
  await newRow.waitFor();
  await rec.settle(page, 900);
  await scrollToY(newRow, 330);
  console.log('new row', (await newRow.innerText()).replace(/\s+/g, ' '));
  await markUnion(page, [newRow]);
  await r.snap(page, 2.5);
  await unmark(page);
}

const flows = { 'kehadiran-isi-form': kehadiranIsiForm, 'kehadiran-import': kehadiranImport, 'aturan-versi-baru': aturanVersiBaru, 'komponen-tambah': komponenTambah };

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
    if (!keepDb) resetDb();
    if (name === 'kehadiran-import') buildImportFile();
    const { browser, page } = await rec.open(BASE, { indonesianUi: true });
    try {
      await login(page, 'demo_superadmin');
      const r = new rec.Recorder(name);
      await flows[name](page, r);
      console.log(JSON.stringify(r.build(path.join(OUT_DIR, name + '.gif'))));
    } finally {
      await browser.close();
    }
  }
})().catch((e) => { console.error(e); process.exit(1); });
