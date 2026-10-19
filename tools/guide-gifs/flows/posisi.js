// Records the Panduan Ubah Posisi GIFs. Usage: node posisi.js [massal|perorangan|timSaya ...]
const path = require('path');
const c = require('./posisi-common.js');
const { rec } = c;

const row = (page, name) => page.locator('tr.fi-ta-row', { hasText: name });

// Tick `names`, open Tindakan > Ubah Posisi Massal, choose `position`, apply, then show the result.
async function bulkChangePosition(page, r, names, position) {
  const boxes = names.map((n) => row(page, n).locator('input[type=checkbox]'));
  await c.highlight(boxes);
  await r.snap(page, 1.3);
  await c.unhighlight(boxes);
  for (const b of boxes) { await b.check(); await page.waitForTimeout(150); }
  await rec.settle(page, 300);

  const toolbar = page.locator('.fi-ta-header-toolbar').first();
  await c.scrollTo(page, toolbar, 70);
  await c.clickStep(page, r, page.locator('.fi-ta-header-toolbar button', { hasText: 'Tindakan' }), 1.2, { scroll: false });
  await c.clickStep(page, r, page.locator('.fi-dropdown-panel button', { hasText: 'Ubah Posisi Massal' }), 1.2, { scroll: false });
  const modal = page.locator('.fi-modal-window', { hasText: 'Ubah Posisi Karyawan Terpilih' });
  await modal.waitFor({ state: 'visible' });
  await c.waitDropdownsClosed(page);
  await rec.settle(page, 500);

  await modal.locator('.fi-select-input-btn').click();
  await rec.settle(page, 300);
  await c.clickStep(page, r, modal.locator('[role=option]', { hasText: position }).first(), 1.3, { scroll: false });
  await page.waitForTimeout(300);
  await c.clickStep(page, r, modal.locator('button', { hasText: 'Terapkan Posisi Baru' }), 1.2, { scroll: false });
  await c.waitNotification(page, 'Posisi Berhasil Diperbarui');
  await modal.waitFor({ state: 'hidden' }).catch(() => {});
  await rec.settle(page, 400);

  // Result: notification at the top, updated Posisi cells below it.
  await c.scrollTo(page, page.locator('.fi-ta-header-toolbar').first(), 130);
  const cells = names.map((n) => row(page, n).getByText(position, { exact: true }));
  await c.highlight(cells);
  await r.snap(page, 2.4); // result frame: build() caps the GIF at the summed durations
}

async function massal() {
  c.resetDb();
  const { browser, page } = await rec.open(c.BASE);
  await c.login(page, 'demo_superadmin');
  await page.goto('/admin/users', { waitUntil: 'domcontentloaded' }); await rec.settle(page);
  await c.collapseSidebar(page);
  const r = new rec.Recorder('ubah-posisi-massal');

  // Filter Area = ULS.
  await c.clickStep(page, r, page.locator('button[title="Filter"]'), 1.2, { scroll: false });
  await c.scrollTo(page, page.locator('.fi-ta-header-toolbar').first(), 70);
  await page.locator('.fi-dropdown-panel .fi-select-input-btn').first().click();
  await rec.settle(page, 300);
  await c.clickStep(page, r, page.locator('.fi-dropdown-panel [role=option]', { hasText: 'ULS' }).first(), 1.3, { scroll: false });
  await page.keyboard.press('Escape');
  await c.waitDropdownsClosed(page);
  await c.scrollTo(page, page.locator('.fi-ta-header-toolbar').first(), 70);

  await bulkChangePosition(page, r, ['IFA PURWANTI', 'BAKIONO LANANG', 'ZULFA SILVIA'], 'Staf Gudang');
  console.log(JSON.stringify(r.build(path.join(c.OUT_DIR, 'ubah-posisi-massal.gif'))));
  await browser.close();
}

async function perorangan() {
  c.resetDb();
  const { browser, page } = await rec.open(c.BASE);
  await c.login(page, 'demo_superadmin');
  await page.goto('/admin/users', { waitUntil: 'domcontentloaded' }); await rec.settle(page);
  await c.collapseSidebar(page);
  const r = new rec.Recorder('ubah-posisi-perorangan');
  const name = 'IFA PURWANTI';
  const newPosition = 'Kepala Toko';

  // 1. Search the employee.
  const search = page.locator('.fi-ta-search-field input').first();
  await search.fill(name);
  await rec.settle(page, 800);
  const searchBox = page.locator('.fi-ta-search-field .fi-input-wrp').first();
  await c.highlight(searchBox);
  await r.snap(page, 1.3);
  await c.unhighlight(searchBox);

  // 2. Ubah (row action at the far right of the table).
  const edit = row(page, name).locator('a', { hasText: 'Ubah' });
  await edit.scrollIntoViewIfNeeded();
  await c.clickStep(page, r, edit, 1.2, { scroll: false });
  await page.waitForURL(/\/edit$/);
  await rec.settle(page, 500);

  // 3-4. Posisi field -> new position.
  const field = page.locator('.fi-fo-field', { has: page.locator('label', { hasText: /^\s*Posisi\s*$/ }) }).first();
  const select = field.locator('.fi-select-input-btn');
  await c.scrollTo(page, page.locator('.fi-section', { hasText: 'Identitas Pengguna' }).first(), 70);
  await c.clickStep(page, r, select, 1.2, { scroll: false });
  await c.scrollTo(page, field, 120); // room for the option list below the field
  await page.waitForTimeout(300);
  await c.clickStep(page, r, field.locator('[role=option]', { hasText: newPosition }).first(), 1.2, { scroll: false });
  await rec.settle(page, 500);

  // 5. Simpan.
  const save = page.locator('button[type=submit]', { hasText: 'Simpan' }).first();
  await c.scrollTo(page, field, 260);
  // Only Simpan is outlined here: drop the select's focus ring so no second box competes with it.
  await page.evaluate(() => document.activeElement && document.activeElement.blur());
  await page.waitForTimeout(200);
  await c.clickStep(page, r, save, 1.3, { scroll: false });
  await c.waitNotification(page, 'disimpan');
  await rec.settle(page, 1200);

  // 6. Result.
  await page.mouse.move(1090, 600); // drop the hover state from Simpan
  await page.waitForTimeout(300);
  await c.highlight(select);
  await r.snap(page, 2.4); // result frame: build() caps the GIF at the summed durations
  console.log(JSON.stringify(r.build(path.join(c.OUT_DIR, 'ubah-posisi-perorangan.gif'))));
  await browser.close();
}

async function timSaya() {
  c.resetDb();
  const { browser, page } = await rec.open(c.BASE);
  await c.login(page, 'demo_teamleader');
  await c.collapseSidebar(page);
  const r = new rec.Recorder('ubah-posisi-tim-saya');

  // 1. Menu Tim Saya (collapsed sidebar shows its tooltip on hover).
  const menu = page.locator('a[href$="/admin/tim-saya"]').first();
  await menu.hover();
  await page.waitForTimeout(500);
  await c.highlight(menu);
  await r.snap(page, 1.3);
  await c.unhighlight(menu);
  await menu.click();
  await page.waitForURL(/\/admin\/tim-saya/, { waitUntil: 'domcontentloaded' });
  await rec.settle(page);
  await page.mouse.move(700, 160);
  await page.waitForTimeout(500);

  // 2-7. Tick team members, bulk action, choose position, apply.
  await bulkChangePosition(page, r, ['BAKIONO LANANG', 'ZULFA SILVIA'], 'Kasir');
  console.log(JSON.stringify(r.build(path.join(c.OUT_DIR, 'ubah-posisi-tim-saya.gif'))));
  await browser.close();
}

const flows = { massal, perorangan, timSaya };
(async () => {
  const which = process.argv.slice(2);
  for (const name of which) if (!flows[name]) throw new Error(`unknown flow ${name} (use ${Object.keys(flows).join(', ')})`);
  for (const name of (which.length ? which : Object.keys(flows))) await flows[name]();
})().catch((e) => { console.error(e); process.exit(1); });
