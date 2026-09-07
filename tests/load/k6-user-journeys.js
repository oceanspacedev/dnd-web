import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Trend, Counter } from 'k6/metrics';

const baseUrl = (__ENV.BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const period = __ENV.PERIOD || new Date().toISOString().slice(0, 7);
const thinkMin = Number(__ENV.THINK_MIN || 0.8);
const thinkMax = Number(__ENV.THINK_MAX || 2.5);
const writeDailyRate = Number(__ENV.WRITE_DAILY_RATE || 0.25);

const staffJourneyDuration = new Trend('staff_journey_duration', true);
const managerJourneyDuration = new Trend('manager_journey_duration', true);
const panelJourneyDuration = new Trend('panel_journey_duration', true);
const journalWrites = new Counter('journal_writes');
const dailyWrites = new Counter('daily_writes');

function num(name, fallback) {
  return Number(__ENV[name] || fallback);
}

const peakProfile = (__ENV.PROFILE || 'office') === 'peak';

function staffStages() {
  if (peakProfile) {
    return [
      { duration: '10s', target: num('STAFF_WARMUP_VUS', 20) },
      { duration: '20s', target: num('STAFF_MORNING_VUS', 45) },
      { duration: '30s', target: num('STAFF_PEAK_VUS', 90) },
      { duration: '40s', target: num('STAFF_PEAK_VUS', 90) },
      { duration: '20s', target: num('STAFF_COOLDOWN_VUS', 20) },
      { duration: '10s', target: 0 },
    ];
  }

  return [
    { duration: '20s', target: num('STAFF_WARMUP_VUS', 12) },
    { duration: '40s', target: num('STAFF_MORNING_VUS', 28) },
    { duration: '50s', target: num('STAFF_PEAK_VUS', 55) },
    { duration: '40s', target: num('STAFF_PEAK_VUS', 55) },
    { duration: '30s', target: num('STAFF_COOLDOWN_VUS', 16) },
    { duration: '20s', target: 0 },
  ];
}

function managerStages() {
  if (peakProfile) {
    return [
      { duration: '10s', target: 4 },
      { duration: '20s', target: 8 },
      { duration: '30s', target: 14 },
      { duration: '40s', target: 14 },
      { duration: '20s', target: 4 },
      { duration: '10s', target: 0 },
    ];
  }

  return [
    { duration: '20s', target: num('MANAGER_WARMUP_VUS', 3) },
    { duration: '40s', target: num('MANAGER_MORNING_VUS', 6) },
    { duration: '50s', target: num('MANAGER_PEAK_VUS', 10) },
    { duration: '40s', target: num('MANAGER_PEAK_VUS', 10) },
    { duration: '30s', target: 3 },
    { duration: '20s', target: 0 },
  ];
}

function panelStages() {
  if (peakProfile) {
    return [
      { duration: '10s', target: 3 },
      { duration: '20s', target: 6 },
      { duration: '30s', target: 10 },
      { duration: '40s', target: 10 },
      { duration: '20s', target: 3 },
      { duration: '10s', target: 0 },
    ];
  }

  return [
    { duration: '20s', target: num('PANEL_WARMUP_VUS', 2) },
    { duration: '40s', target: num('PANEL_MORNING_VUS', 4) },
    { duration: '50s', target: num('PANEL_PEAK_VUS', 7) },
    { duration: '40s', target: num('PANEL_PEAK_VUS', 7) },
    { duration: '30s', target: 2 },
    { duration: '20s', target: 0 },
  ];
}

export const options = {
  scenarios: {
    staff: {
      executor: 'ramping-vus',
      exec: 'staffJourney',
      startVUs: 0,
      stages: staffStages(),
      gracefulRampDown: '15s',
    },
    manager: {
      executor: 'ramping-vus',
      exec: 'managerJourney',
      startTime: '5s',
      startVUs: 0,
      stages: managerStages(),
      gracefulRampDown: '15s',
    },
    panel: {
      executor: 'ramping-vus',
      exec: 'panelJourney',
      startTime: '5s',
      startVUs: 0,
      stages: panelStages(),
      gracefulRampDown: '15s',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.05'],
    http_req_duration: ['p(95)<4000'],
    checks: ['rate>0.95'],
    staff_journey_duration: ['p(95)<12000'],
    manager_journey_duration: ['p(95)<12000'],
    panel_journey_duration: ['p(95)<8000'],
  },
};

function apiHeaders(token) {
  return {
    Accept: 'application/json',
    Authorization: `Bearer ${token}`,
  };
}

function jsonHeaders(token) {
  return {
    ...apiHeaders(token),
    'Content-Type': 'application/json',
  };
}

function think() {
  const span = Math.max(0, thinkMax - thinkMin);
  sleep(thinkMin + Math.random() * span);
}

function asJson(response) {
  if (!response || !response.body) {
    return null;
  }

  try {
    return response.json();
  } catch (error) {
    return null;
  }
}

function pick(list, fallback) {
  if (!list || list.length === 0) {
    return fallback;
  }

  return list[(__VU - 1) % list.length];
}

export function setup() {
  const adminToken = __ENV.ADMIN_TOKEN;
  if (!adminToken) {
    throw new Error('ADMIN_TOKEN wajib diisi.');
  }

  const staffTokens = String(__ENV.STAFF_TOKENS || '')
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean)
    .map((entry) => {
      const [token, userId] = entry.split(':');

      return { token, userId: Number(userId || 0) };
    });

  const managerToken = __ENV.MANAGER_TOKEN || adminToken;
  const me = http.get(`${baseUrl}/api/v1/auth/me`, { headers: apiHeaders(adminToken) });
  const meBody = asJson(me);

  check(me, { 'setup admin me 200': (result) => result.status === 200 });

  const panelCookies = filamentLogin(
    __ENV.PANEL_USER || 'admin',
    __ENV.PANEL_PASSWORD || 'complete123',
  );

  return {
    adminToken,
    adminUserId: meBody?.data?.id || 1,
    managerToken,
    staffTokens,
    period,
    panelCookies,
  };
}

function filamentLogin(username, password) {
  const loginPage = http.get(`${baseUrl}/admin/login`, {
    headers: { Accept: 'text/html' },
  });

  if (loginPage.status !== 200) {
    return {};
  }

  const snapshotMatch = loginPage.body.match(/wire:snapshot="([^"]+)"/);
  const csrfMatch = loginPage.body.match(/name="csrf-token" content="([^"]+)"/);

  if (!snapshotMatch || !csrfMatch) {
    return {};
  }

  const snapshot = snapshotMatch[1]
    .replace(/&quot;/g, '"')
    .replace(/&amp;/g, '&');

  const payload = JSON.stringify({
    _token: csrfMatch[1],
    components: [
      {
        snapshot,
        updates: {
          'data.login': username,
          'data.password': password,
        },
        calls: [{ method: 'authenticate', params: [] }],
      },
    ],
  });

  const login = http.post(`${baseUrl}/livewire/update`, payload, {
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Livewire': 'true',
      'X-CSRF-TOKEN': csrfMatch[1],
    },
  });

  check(login, {
    'filament login accepted': (result) => result.status === 200 || result.status === 302,
  });

  return http.cookieJar().cookiesForURL(baseUrl);
}

function applyCookies(cookieMap) {
  if (!cookieMap) {
    return;
  }

  const jar = http.cookieJar();
  Object.entries(cookieMap).forEach(([name, values]) => {
    const value = Array.isArray(values) ? values[0] : values;
    if (value) {
      jar.set(baseUrl, name, value);
    }
  });
}

function ok(response) {
  return response && response.status >= 200 && response.status < 400;
}

export function staffJourney(data) {
  const started = Date.now();
  const staff = pick(data.staffTokens, { token: data.adminToken, userId: data.adminUserId });
  const headers = apiHeaders(staff.token);
  const json = jsonHeaders(staff.token);
  const userId = staff.userId || data.adminUserId;

  group('staff open app', () => {
    const me = http.get(`${baseUrl}/api/v1/auth/me`, { headers });
    check(me, { 'staff me 200': ok });
  });
  think();

  group('staff journals', () => {
    const list = http.get(`${baseUrl}/api/v1/journals?per_page=15`, { headers });
    const today = http.get(`${baseUrl}/api/v1/journals/today`, { headers });
    check(list, { 'staff journals 200': ok });
    check(today, { 'staff today 200': ok });

    const todayBody = asJson(today);
    const existingId = todayBody?.data?.id;
    const stamp = `k6 ${new Date().toISOString()} vu=${__VU} iter=${__ITER}`;

    if (existingId) {
      const update = http.put(
        `${baseUrl}/api/v1/journals/${existingId}`,
        JSON.stringify({
          activity: `Update jurnal harian ${stamp}`,
          notes: 'Catatan dari skenario staf k6.',
        }),
        { headers: json },
      );
      check(update, { 'staff journal update 200': ok });
      if (ok(update)) {
        journalWrites.add(1);
      }
    } else {
      const create = http.post(
        `${baseUrl}/api/v1/journals`,
        JSON.stringify({
          activity: `Jurnal harian ${stamp}`,
          notes: 'Jurnal baru dari skenario staf k6.',
        }),
        { headers: json },
      );
      check(create, { 'staff journal create 201': (result) => result.status === 201 || result.status === 200 });
      if (create.status === 201 || create.status === 200) {
        journalWrites.add(1);
      }
    }
  });
  think();

  group('staff work', () => {
    const dailies = http.get(`${baseUrl}/api/v1/activities/dailies?per_page=15`, { headers });
    const weeklies = http.get(`${baseUrl}/api/v1/activities/weeklies?per_page=15`, { headers });
    check(dailies, { 'staff dailies 200': ok });
    check(weeklies, { 'staff weeklies 200': ok });

    if (Math.random() < writeDailyRate) {
      const created = http.post(
        `${baseUrl}/api/v1/activities/dailies`,
        JSON.stringify({
          task: `Task k6 vu=${__VU} t=${Date.now()}`,
          date: new Date().toISOString().slice(0, 10),
          status: 0,
          ontime: true,
          isplan: true,
        }),
        { headers: json },
      );
      check(created, { 'staff daily create 201': (result) => result.status === 201 });
      if (created.status === 201) {
        dailyWrites.add(1);
      }
    }
  });
  think();

  group('staff kpi', () => {
    const checklist = http.get(
      `${baseUrl}/api/v1/analytics/kpi-checklist?user_id=${userId}&periode=${data.period}`,
      { headers },
    );
    const summary = http.get(
      `${baseUrl}/api/v1/kpis/user/${userId}/summary?periode=${data.period}`,
      { headers },
    );
    check(checklist, { 'staff checklist 200': ok });
    check(summary, { 'staff summary 200': ok });
  });
  think();

  staffJourneyDuration.add(Date.now() - started);
}

export function managerJourney(data) {
  const started = Date.now();
  const headers = apiHeaders(data.managerToken);

  group('manager dashboard', () => {
    const dashboard = http.get(`${baseUrl}/api/v1/analytics/dashboard?periode=${data.period}`, { headers });
    const leaderboard = http.get(
      `${baseUrl}/api/v1/analytics/leaderboard?periode=${data.period}&per_page=50`,
      { headers },
    );
    check(dashboard, { 'manager dashboard 200': ok });
    check(leaderboard, { 'manager leaderboard 200': ok });
  });
  think();

  group('manager org', () => {
    const stats = http.get(
      `${baseUrl}/api/v1/analytics/department-stats?periode=${data.period}`,
      { headers },
    );
    const users = http.get(`${baseUrl}/api/v1/users?per_page=25`, { headers });
    const team = http.get(`${baseUrl}/api/v1/journals/team?per_page=15`, { headers });
    check(stats, { 'manager dept stats 200': ok });
    check(users, { 'manager users 200': ok });
    check(team, { 'manager team journals 200': ok });
  });
  think();

  group('manager review', () => {
    const pending = http.get(`${baseUrl}/api/v1/requests/pending-approvals`, { headers });
    const reviews = http.get(`${baseUrl}/api/v1/employee-reviews?per_page=25`, { headers });
    const attendances = http.get(`${baseUrl}/api/v1/attendances?per_page=25`, { headers });
    check(pending, { 'manager pending 200': ok });
    check(reviews, { 'manager reviews 200': ok });
    check(attendances, { 'manager attendances 200': ok });
  });
  think();

  managerJourneyDuration.add(Date.now() - started);
}

export function panelJourney(data) {
  const started = Date.now();
  applyCookies(data.panelCookies);

  const pages = [
    ['dashboard', `${baseUrl}/admin`],
    ['kpis', `${baseUrl}/admin/kpis`],
    ['users', `${baseUrl}/admin/users`],
    ['journals', `${baseUrl}/admin/work-journals`],
    ['leaderboard', `${baseUrl}/admin?tab=leaderboard`],
  ];

  pages.forEach(([name, url], index) => {
    group(`panel ${name}`, () => {
      const response = http.get(url, {
        headers: { Accept: 'text/html' },
        redirects: 0,
      });
      check(response, {
        [`panel ${name} authenticated`]: (result) => result.status === 200,
      });
    });

    if (index < pages.length - 1) {
      think();
    }
  });

  panelJourneyDuration.add(Date.now() - started);
}
