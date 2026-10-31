// Shared helpers for recording dnd-web guide GIFs (headless Chromium, 1100x618, light theme).
// Usage from a flow script: const rec = require('../lib.js');
const os = require('os');
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { chromium } = require('playwright-core');

const TOOL_DIR = __dirname;
const REPO = path.resolve(TOOL_DIR, '../..');
// Frames, the demo DB dump, the generated import file and debug screenshots (git-ignored).
const WORK_DIR = path.resolve(process.env.GUIDE_GIFS_WORK_DIR || path.join(TOOL_DIR, '.work'));
const PASSWORD = 'uji-lokal-2026'; // local demo password (see demo_seed.php)

// One demo database (dnd_demo_<key>) and php -S port per guide, so the recordings never share state.
// Keep in sync with serve.sh, reset.sh and setup-db.sh.
const GUIDES = {
  posisi: { port: 8011, outDir: 'panduan-ubah-posisi' },
  akses: { port: 8012, outDir: 'panduan-akses-data' },
  aturan: { port: 8013, outDir: 'panduan-aturan-penilaian' },
  kpimanajerial: { port: 8014, outDir: 'kpi-guides' },
  kpioperasional: { port: 8015, outDir: 'kpi-guides' },
};

// Environment that every php process of a recording must run with (WhatsApp -> local stub with a dummy token,
// mail -> mail-stub.php, cache -> array, empty DB_URL so DB_DATABASE is the database). Read from env.sh so the shell scripts and the flows share one source; spread it into
// the env of any php child process: execFileSync('php', [...], { env: { ...process.env, ...rec.SAFE_ENV, DB_DATABASE } }).
const SAFE_ENV = (() => {
  // Source env.sh in a clean shell and keep only its exports.
  const out = execFileSync('sh', ['-c', '. "$1" && env', 'sh', path.join(TOOL_DIR, 'env.sh')], { encoding: 'utf8', env: { PATH: process.env.PATH, HERE: TOOL_DIR } });
  const all = Object.fromEntries(out.split('\n').filter((l) => l.includes('=')).map((l) => [l.slice(0, l.indexOf('=')), l.slice(l.indexOf('=') + 1)]));
  const env = Object.fromEntries(['WAG_URL', 'WAG_TOKEN', 'MAIL_MAILER', 'MAIL_SENDMAIL_PATH', 'MAIL_FROM_ADDRESS', 'DEBUGBAR_ENABLED', 'KPI_CHECKLIST_LOCK_DAYS', 'CACHE_STORE', 'DB_URL'].map((k) => [k, all[k]]));
  if (env.MAIL_MAILER !== 'sendmail' || env.MAIL_SENDMAIL_PATH !== `php ${path.join(TOOL_DIR, 'mail-stub.php')} -t -i`) throw new Error('env.sh must point the mailer at mail-stub.php');
  if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(env.WAG_URL || '') || !env.WAG_TOKEN) throw new Error('env.sh must point WAG_URL at the local stub');
  if (env.DB_URL !== '') throw new Error('env.sh must export an empty DB_URL');
  return env;
})();
// The local WhatsApp gateway stub (wa-stub.php); serve.sh starts it when it is not running.
const WA_STUB = SAFE_ENV.WAG_URL;

function baseUrl(guide) {
  return `http://127.0.0.1:${GUIDES[guide].port}`;
}

// Where a guide's GIFs land: public/images/<guide folder>, or GUIDE_GIFS_OUT_DIR to preview them elsewhere.
function outDir(guide) {
  return process.env.GUIDE_GIFS_OUT_DIR ? path.resolve(process.env.GUIDE_GIFS_OUT_DIR) : path.join(REPO, 'public/images', GUIDES[guide].outDir);
}

function debugPath(file) {
  const dir = path.join(WORK_DIR, 'debug');
  fs.mkdirSync(dir, { recursive: true });
  return path.join(dir, file);
}

// Restore the guide's demo DB from the seeded dump (see reset.sh). Run through sh: the scripts carry a macOS
// provenance attribute that can block direct execution.
function resetDb(guide) {
  execFileSync('sh', [path.join(TOOL_DIR, 'reset.sh'), guide], { stdio: 'inherit' });
}

// Latest WhatsApp message the app sent to `phone` through the stub, received at or after `since` (ms since epoch),
// or null when there is none yet. Returns { time, time_ms, recipient, text }.
async function lastWaMessage(phone, since = 0) {
  const res = await fetch(`${WA_STUB}/last?phone=${encodeURIComponent(phone)}&since=${since}`);
  if (res.status === 404) return null;
  if (!res.ok) throw new Error(`wa-stub answered ${res.status}`);
  return res.json();
}

// Wait for the OTP the app sent to `phone` (any spelling: 0812-0000-0101, 081200000101, +62…) and return its 6 digits.
// Take `since = Date.now()` right before clicking "Kirim OTP WhatsApp" so an older OTP is never picked up.
// Throws when nothing arrives within `timeoutMs` (stub down, wrong number, or the app did not send).
async function readOtp(phone, { since = 0, timeoutMs = 15000 } = {}) {
  const deadline = Date.now() + timeoutMs;
  let lastError = null;
  while (Date.now() < deadline) {
    try {
      const msg = await lastWaMessage(phone, since);
      if (msg) {
        const m = /\b(\d{6})\b/.exec(msg.text || '');
        if (!m) throw new Error(`WhatsApp message to ${msg.recipient} has no 6-digit code`);
        return m[1];
      }
    } catch (e) {
      if (/no 6-digit code/.test(e.message)) throw e;
      lastError = e;
    }
    await new Promise((resolve) => setTimeout(resolve, 250));
  }
  throw new Error(`no WhatsApp OTP for ${phone} reached the stub at ${WA_STUB} within ${timeoutMs} ms` + (lastError ? ` (${lastError.message})` : ''));
}

// Messages the app "sent" through the sendmail stub (mail-stub.php), oldest first: { time_ms, recipients, raw }.
function mailOutbox() {
  const file = path.join(WORK_DIR, 'mail-outbox.jsonl');
  if (!fs.existsSync(file)) return [];
  return fs.readFileSync(file, 'utf8').split('\n').filter(Boolean).map((l) => JSON.parse(l));
}

// Decode one MIME part body by its Content-Transfer-Encoding (quoted-printable, base64 or plain).
function decodePart(headers, body) {
  const enc = (/content-transfer-encoding:\s*([^\s;]+)/i.exec(headers) || [])[1] || '';
  if (/quoted-printable/i.test(enc)) {
    return Buffer.from(body.replace(/=\r?\n/g, '').replace(/=([0-9A-F]{2})/gi, (_, h) => String.fromCharCode(parseInt(h, 16))), 'latin1').toString('utf8');
  }
  if (/base64/i.test(enc)) return Buffer.from(body.replace(/\s+/g, ''), 'base64').toString('utf8');
  return body;
}

// Latest e-mail sent to `email` at or after `since` (ms since epoch), parsed: { subject, text, code, link }.
// `text` is the text/plain part; `code` the 6-digit login code; `link` the magic login link. Waits up to `timeoutMs`.
async function readEmail(email, { since = 0, timeoutMs = 15000 } = {}) {
  const deadline = Date.now() + timeoutMs;
  const want = email.toLowerCase();
  while (Date.now() < deadline) {
    const msg = mailOutbox().filter((m) => m.time_ms >= since && m.recipients.some((r) => r.toLowerCase().includes(want))).pop();
    if (msg) {
      const raw = msg.raw.replace(/\r\n/g, '\n');
      const subject = ((/^subject:\s*(.*)$/im.exec(raw) || [])[1] || '').trim();
      const parts = raw.split(/\n--[^\n]+\n/).map((p) => { const i = p.indexOf('\n\n'); return i < 0 ? null : [p.slice(0, i), p.slice(i + 2)]; }).filter(Boolean);
      const plain = parts.find(([h]) => /content-type:\s*text\/plain/i.test(h));
      const text = plain ? decodePart(plain[0], plain[1]).replace(/\n--\s*$/, '').trim() : '';
      const code = (/(?:^|\s)(\d{6})(?:\s|$)/.exec(text) || [])[1];
      const link = (/https?:\/\/\S+\/email-login\/[A-Za-z0-9]+/.exec(text) || [])[0];
      if (!code) throw new Error(`e-mail to ${email} has no 6-digit code`);
      return { subject, text, code, link };
    }
    await new Promise((resolve) => setTimeout(resolve, 250));
  }
  throw new Error(`no e-mail for ${email} reached mail-stub.php within ${timeoutMs} ms (is the guide server running with env.sh?)`);
}

function playwrightCacheDir() {
  const env = process.env.PLAYWRIGHT_BROWSERS_PATH;
  if (env === '0') return path.join(path.dirname(require.resolve('playwright-core/package.json')), '.local-browsers');
  if (env) return env;
  if (process.platform === 'darwin') return path.join(os.homedir(), 'Library/Caches/ms-playwright');
  if (process.platform === 'win32') return path.join(process.env.LOCALAPPDATA || os.homedir(), 'ms-playwright');
  return path.join(process.env.XDG_CACHE_HOME || path.join(os.homedir(), '.cache'), 'ms-playwright');
}

// CHROMIUM_PATH, else Playwright's cached headless shell: the revision this playwright-core expects, or the newest one cached.
function chromiumPath() {
  if (process.env.CHROMIUM_PATH) return process.env.CHROMIUM_PATH;
  const cache = playwrightCacheDir();
  const browsers = JSON.parse(fs.readFileSync(path.join(path.dirname(require.resolve('playwright-core/package.json')), 'browsers.json'), 'utf8')).browsers;
  const expected = browsers.find((b) => b.name === 'chromium-headless-shell').revision;
  const revisions = (fs.existsSync(cache) ? fs.readdirSync(cache) : [])
    .map((d) => /^chromium_headless_shell-(\d+)$/.exec(d)).filter(Boolean).map((m) => m[1])
    .sort((a, b) => (a === expected ? -1 : b === expected ? 1 : Number(b) - Number(a)));
  for (const revision of revisions) {
    const dir = path.join(cache, 'chromium_headless_shell-' + revision);
    for (const sub of fs.readdirSync(dir).filter((d) => d.startsWith('chrome-headless-shell-'))) {
      for (const bin of ['chrome-headless-shell', 'chrome-headless-shell.exe']) {
        if (fs.existsSync(path.join(dir, sub, bin))) return path.join(dir, sub, bin);
      }
    }
  }
  throw new Error(`No Chromium headless shell in ${cache}. Run "npm run install-browser" or set CHROMIUM_PATH.`);
}

// `indonesianUi` also sets the browser UI language, so native month inputs read e.g. "Desember 2026".
async function open(url, { indonesianUi = false } = {}) {
  const launch = { executablePath: chromiumPath(), headless: true };
  if (indonesianUi) Object.assign(launch, { args: ['--lang=id-ID'], env: { ...process.env, LANG: 'id_ID.UTF-8', LANGUAGE: 'id' } });
  const browser = await chromium.launch(launch);
  const context = await browser.newContext({
    viewport: { width: 1100, height: 618 },
    deviceScaleFactor: 1,
    colorScheme: 'light',
    locale: 'id-ID',
    baseURL: url,
  });
  const page = await context.newPage();
  page.setDefaultTimeout(15000);
  return { browser, context, page };
}

async function login(page, username) {
  await page.goto('/admin/login');
  await page.locator('input[type=text]').first().fill(username);
  await page.locator('input[type=password]').first().fill(PASSWORD);
  await Promise.all([page.waitForURL(/\/admin(\?.*)?$/), page.locator('button[type=submit]').first().click()]);
  await settle(page);
}

async function settle(page, ms = 400) {
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(ms);
}

// Red outline around the element the reader should look at / click (recording only).
async function highlight(locator) {
  await locator.evaluate((el) => {
    el.dataset.recOutline = el.style.outline;
    el.style.outline = '3px solid #ef4444';
    el.style.outlineOffset = '2px';
  });
}

async function unhighlight(locator) {
  await locator.evaluate((el) => {
    el.style.outline = el.dataset.recOutline || '';
    el.style.outlineOffset = '';
  }).catch(() => {});
}

// Build a GIF from a concat list at 10 fps (like the existing guide GIFs) with a shared 128-colour palette.
// The list repeats its last file (the concat demuxer needs it); the length is capped inside the filter graph
// so that repeated entry never holds the final frame twice as long.
function buildGif(listFile, outFile) {
  const durations = fs.readFileSync(listFile, 'utf8').split('\n').filter((l) => l.startsWith('duration ')).map((l) => parseFloat(l.slice(9)));
  if (durations.length === 0) throw new Error('no frames in ' + listFile);
  const total = Math.round(durations.reduce((s, d) => s + d, 0) * 10) / 10;
  fs.mkdirSync(path.dirname(outFile), { recursive: true });
  execFileSync('ffmpeg', ['-v', 'error', '-y', '-f', 'concat', '-safe', '0', '-i', listFile,
    '-vf', `fps=10,trim=duration=${total},split[a][b];[a]palettegen=max_colors=128:stats_mode=full[p];[b][p]paletteuse=dither=none`,
    '-frames:v', String(Math.round(total * 10)), '-loop', '0', outFile]);
  return { outFile, frames: durations.length, seconds: total, bytes: fs.statSync(outFile).size };
}

function framesDir(name) {
  return path.join(WORK_DIR, 'frames', name);
}

class Recorder {
  constructor(name) {
    this.name = name;
    this.dir = framesDir(name);
    fs.rmSync(this.dir, { recursive: true, force: true });
    fs.mkdirSync(this.dir, { recursive: true });
    this.frames = [];
  }

  // Capture the viewport and hold it for `seconds` in the GIF.
  async snap(page, seconds = 1.0) {
    const file = path.join(this.dir, String(this.frames.length + 1).padStart(3, '0') + '.png');
    await page.screenshot({ path: file });
    this.frames.push({ file, seconds });
    return file;
  }

  // Highlight, capture, click, settle.
  async clickStep(page, locator, seconds = 1.0) {
    await locator.scrollIntoViewIfNeeded();
    await highlight(locator);
    await this.snap(page, seconds);
    await unhighlight(locator);
    await locator.click();
    await settle(page);
  }

  // Concat list for the recorded frames (last file repeated, as the concat demuxer needs).
  writeList() {
    if (this.frames.length === 0) throw new Error('no frames');
    const list = path.join(this.dir, 'list.txt');
    const lines = [];
    for (const f of this.frames) lines.push(`file '${f.file}'`, `duration ${f.seconds}`);
    lines.push(`file '${this.frames[this.frames.length - 1].file}'`);
    fs.writeFileSync(list, lines.join('\n') + '\n');
    return list;
  }

  build(outFile) {
    return buildGif(this.writeList(), outFile);
  }
}

module.exports = {
  open, login, settle, highlight, unhighlight, Recorder, buildGif, framesDir,
  baseUrl, outDir, debugPath, resetDb, chromiumPath, readOtp, lastWaMessage,
  REPO, TOOL_DIR, WORK_DIR, PASSWORD, GUIDES, SAFE_ENV, WA_STUB, readEmail,
};
