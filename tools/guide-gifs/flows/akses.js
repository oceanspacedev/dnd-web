// Panduan Akses Data: records the three GIFs for resources/views/filament/pages/data-access-guide.blade.php.
// Resets dnd_demo_akses first unless --keep-db (GIF 1 makes demo_admin_bu an ADMIN for ULS + GMD, GIF 2 relies on that).
// Usage: node akses.js [1|2|3 ...] [--keep-db]   (default: all, in order)
const path = require('path');
const { rec, BASE, login, reveal, resetWindow, step, hl, unhl, snapHighlighted, parkMouse, boxOverlay, columnIndex, scrollContentTop } = require('./akses-helpers.js');

const OUT_DIR = rec.outDir('akses');

function sideLink(page, label) {
  return page.locator('.mky-si .mky-sidebar-item-link').filter({ hasText: new RegExp('^\\s*' + label + '\\s*$') }).first();
}

async function searchEmployees(page, text) {
  const search = page.locator('input[type=search]').first();
  await search.fill(text);
  await rec.settle(page, 1200);
  await resetWindow(page);
  return search;
}

async function scrollTableRight(page) {
  await page.evaluate(() => {
    const tc = document.querySelector('.fi-ta-content-ctn');
    if (tc) tc.scrollLeft = tc.scrollWidth;
  });
  await page.waitForTimeout(250);
}

// Searchable Filament select inside `field`: open it and return its listbox.
async function openSelect(page, field) {
  const combo = field.getByRole('combobox').first();
  await combo.click();
  await rec.settle(page, 500);
  await resetWindow(page);
  return page.locator('#' + (await combo.getAttribute('aria-controls')));
}

// GIF 1: super admin promotes "Admin Area" (a COO staff member in the demo seed) to ADMIN. Akses Data opens on
// Area Tertentu with the employee's own Area (COO) already filled in in Area yang Dikelola; COO is replaced by GMD + ULS.
async function gifAturAdmin() {
  const { browser, page } = await rec.open(BASE);
  try {
    await login(page, 'demo_superadmin');
    const r = new rec.Recorder('akses-data-atur-admin');

    // Sidebar > Karyawan (not captured: the next frame shows Karyawan active in the sidebar).
    await sideLink(page, 'Karyawan').click();
    await rec.settle(page, 700);
    await parkMouse(page);
    await resetWindow(page);

    // 1. Menu Karyawan: search the employee's name (SOP step 3).
    const search = await searchEmployees(page, 'Admin Area');
    await snapHighlighted(r, page, search.locator('xpath=ancestor::div[contains(concat(" ",normalize-space(@class)," ")," fi-input-wrp ")][1]'), 1.3);

    // 2. Ubah on that row (table scrolled right so the action is visible).
    await scrollTableRight(page);
    await step(r, page, page.locator('tbody tr').first().getByRole('link', { name: 'Ubah' }), 1.2, { noReveal: true });

    // 3. Identitas Pengguna > Jabatan (STAFF) > ADMIN (SOP step 4).
    const jabatan = page.locator('.fi-fo-field').filter({ has: page.locator('label', { hasText: /^\s*Jabatan\*?\s*$/ }) }).first();
    await reveal(page, jabatan, 200);
    await parkMouse(page);
    const roles = await openSelect(page, jabatan);
    await step(r, page, roles.getByRole('option', { name: 'ADMIN', exact: true }), 1.4, { noReveal: true });
    await rec.settle(page, 600);

    // 4. Akses Data appears: Cakupan Area = Area Tertentu, Area yang Dikelola = own Area (COO) (SOP step 5).
    const section = page.getByText('Akses Data', { exact: true }).first();
    await reveal(page, section, 75);
    await parkMouse(page);
    const scope = page.locator('.fi-fo-toggle-buttons-wrp').filter({ hasText: 'Cakupan Area' }).first();
    const field = page.locator('.fi-fo-field', { hasText: 'Admin melihat karyawan di area ini' }).first();
    await hl(scope);
    await hl(field);
    await page.waitForTimeout(80);
    await r.snap(page, 2.6);
    await unhl(scope);
    await unhl(field);

    // 5. Area yang Dikelola: remove COO (x on its badge), then pick GMD and ULS (SOP step 6). Selected options leave the list.
    // The x button is tiny: outline the whole COO badge, then click its x.
    const cooBadge = field.locator('.fi-badge', { hasText: 'COO' }).first();
    await parkMouse(page);
    await snapHighlighted(r, page, cooBadge, 1.3);
    await field.locator('[aria-label="Remove COO"]').first().click();
    await rec.settle(page, 500);
    await page.keyboard.press('Escape');
    await rec.settle(page, 300);
    await reveal(page, section, 75);
    const areas = await openSelect(page, field);
    await step(r, page, areas.getByRole('option', { name: 'GMD', exact: true }), 1.1, { noReveal: true });
    await reveal(page, section, 75);
    await step(r, page, areas.getByRole('option', { name: 'ULS', exact: true }), 1.1, { noReveal: true });
    await page.keyboard.press('Escape');
    await rec.settle(page, 400);
    await resetWindow(page);
    await reveal(page, section, 75);
    await parkMouse(page);
    await snapHighlighted(r, page, field, 1.6);

    // 6. Simpan (SOP step 7).
    const save = page.getByRole('button', { name: 'Simpan', exact: true });
    await step(r, page, save, 1.2, { noReveal: true });
    await page.waitForTimeout(600);

    // 7. Saved (notification) > back to Karyawan.
    await step(r, page, sideLink(page, 'Karyawan'), 1.3, { noReveal: true });

    // 8. Result: Jabatan ADMIN and column Akses Data shows the chosen areas (SOP step 8).
    await searchEmployees(page, 'Admin Area');
    await scrollTableRight(page);
    await page.locator('tbody tr').first().locator('.fi-badge', { hasText: 'GMD' }).first().waitFor();
    await parkMouse(page);
    // One box from Jabatan (ADMIN) to Akses Data (badge with the chosen areas).
    const jabatanCol = await columnIndex(page, 'Jabatan');
    const accessCol = await columnIndex(page, 'Akses Data');
    const clear = await boxOverlay(page, `.fi-ta-table tbody tr:first-child td:nth-child(${jabatanCol}), .fi-ta-table tbody tr:first-child td:nth-child(${accessCol})`);
    await r.snap(page, 2.4);
    await clear();

    console.log(JSON.stringify(r.build(path.join(OUT_DIR, 'akses-data-atur-admin.gif'))));
  } finally {
    await browser.close();
  }
}

// GIF 2: what the restricted admin sees (only ULS / GMD employees, no Settings group).
async function gifHasilAdmin() {
  const { browser, page } = await rec.open(BASE);
  try {
    await login(page, 'demo_admin_bu');
    const r = new rec.Recorder('akses-data-hasil-admin');
    await parkMouse(page);

    // 1. Sidebar: no Settings / KPI Settings group.
    const nav = page.locator('.mky-si .mky-si-nav').first();
    let clear = await boxOverlay(page, '.mky-si .mky-si-nav > *');
    await r.snap(page, 1.8);
    await clear();

    // 2. Karyawan.
    await step(r, page, sideLink(page, 'Karyawan'), 1.0, { noReveal: true });

    // 3. Area column: only ULS and GMD.
    await reveal(page, page.locator('.fi-ta-table thead').first(), 70);
    await page.evaluate(() => { const tc = document.querySelector('.fi-ta-content-ctn'); if (tc) tc.scrollLeft = 0; });
    await page.waitForTimeout(200);
    const col = await columnIndex(page, 'Area');
    clear = await boxOverlay(page, `.fi-ta-table thead th:nth-child(${col}), .fi-ta-table tbody td:nth-child(${col})`);
    await r.snap(page, 2.0);
    await clear();

    // 4. Filter > Area: only the granted areas are offered.
    const filterBtn = page.getByRole('button', { name: 'Filter', exact: true }).first();
    await scrollContentTop(page);
    await step(r, page, filterBtn, 1.0, { noReveal: true });
    const areaField = page.locator('.fi-ta-filters .fi-fo-field, .fi-dropdown-panel .fi-fo-field').filter({ hasText: 'Area' }).first();
    const combo = areaField.getByRole('combobox').first();
    await combo.click();
    await rec.settle(page, 500);
    await resetWindow(page);
    await parkMouse(page);
    const listbox = page.locator('#' + (await combo.getAttribute('aria-controls')));
    await snapHighlighted(r, page, listbox, 2.2);

    console.log(JSON.stringify(r.build(path.join(OUT_DIR, 'akses-data-hasil-admin.gif'))));
  } finally {
    await browser.close();
  }
}

// GIF 3: team leader opens Tim Saya (members incl. those in GMD) and opens a GMD member to show why it is listed (Approval Line).
async function gifTimSaya() {
  const { browser, page } = await rec.open(BASE);
  try {
    await login(page, 'demo_teamleader');
    const r = new rec.Recorder('akses-data-tim-saya');
    await parkMouse(page);

    // 1. Sidebar > Tim Saya.
    await step(r, page, sideLink(page, 'Tim Saya'), 1.2, { noReveal: true });

    // 2. Members by Approval Line, across areas (ULS and GMD).
    await reveal(page, page.locator('.fi-ta-table thead').first(), 70);
    await page.evaluate(() => { const tc = document.querySelector('.fi-ta-content-ctn'); if (tc) tc.scrollLeft = 0; });
    await page.waitForTimeout(200);
    const col = await columnIndex(page, 'Area');
    const clear = await boxOverlay(page, `.fi-ta-table thead th:nth-child(${col}), .fi-ta-table tbody td:nth-child(${col})`);
    await r.snap(page, 2.0);
    await clear();

    // 3. Ubah on the first GMD member.
    const gmdRow = page.locator('.fi-ta-table tbody tr').filter({ has: page.locator(`td:nth-child(${col})`, { hasText: /^\s*GMD\s*$/ }) }).first();
    await scrollTableRight(page);
    await step(r, page, gmdRow.getByRole('link', { name: 'Ubah' }), 1.3, { noReveal: true });

    // 4. Edit form: Area GMD.
    const areaField = page.locator('.fi-fo-field').filter({ has: page.locator('label', { hasText: /^\s*Area\*?\s*$/ }) }).first();
    await parkMouse(page);
    await snapHighlighted(r, page, areaField, 1.5);

    // 5. Approval Line = the team leader: that is why the member is in Tim Saya.
    const approvalField = page.locator('.fi-fo-field').filter({ has: page.locator('label', { hasText: /^\s*Approval Line\s*$/ }) }).first();
    await reveal(page, approvalField, 260);
    await snapHighlighted(r, page, approvalField, 2.2);

    console.log(JSON.stringify(r.build(path.join(OUT_DIR, 'akses-data-tim-saya.gif'))));
  } finally {
    await browser.close();
  }
}

(async () => {
  const keepDb = process.argv.includes('--keep-db');
  const which = process.argv.slice(2).filter((a) => a !== '--keep-db');
  const run = (n) => which.length === 0 || which.includes(String(n));
  if (run(2) && !run(1) && !keepDb) throw new Error('GIF 2 shows the result of GIF 1: run "node akses.js 1 2", or add --keep-db right after recording GIF 1');
  if (!keepDb) rec.resetDb('akses');
  if (run(1)) await gifAturAdmin();
  if (run(2)) await gifHasilAdmin();
  if (run(3)) await gifTimSaya();
})().catch((e) => { console.error(e); process.exit(1); });
