import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Trend } from 'k6/metrics';

const baseUrl = (__ENV.BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const period = __ENV.PERIOD || new Date().toISOString().slice(0, 7);
const thinkSeconds = Number(__ENV.THINK_SECONDS || 0);
const leaderboardDuration = new Trend('leaderboard_duration', true);
const dashboardDuration = new Trend('dashboard_duration', true);
const panelDuration = new Trend('panel_duration', true);

function scenario(exec, startTime, arrival, vus) {
  if ((__ENV.EXECUTOR || 'arrival') === 'vus') {
    return {
      executor: 'constant-vus',
      vus: Number(vus),
      duration: __ENV.DURATION || '45s',
      exec,
      startTime,
    };
  }

  return {
    executor: 'constant-arrival-rate',
    rate: Number(arrival.rate),
    timeUnit: '1s',
    duration: __ENV.DURATION || '45s',
    preAllocatedVUs: Number(__ENV.PRE_ALLOCATED_VUS || arrival.preAllocatedVUs),
    maxVUs: Number(__ENV.MAX_VUS || arrival.maxVUs),
    exec,
    startTime,
  };
}

export const options = {
  scenarios: {
    staff: scenario('staffFlow', '0s', {
      rate: __ENV.STAFF_RPS || 15,
      preAllocatedVUs: 20,
      maxVUs: 120,
    }, __ENV.STAFF_VUS || 30),
    analytics: scenario('analyticsFlow', '1s', {
      rate: __ENV.ANALYTICS_RPS || 8,
      preAllocatedVUs: 15,
      maxVUs: 80,
    }, __ENV.ANALYTICS_VUS || 10),
    panel: scenario('panelFlow', '1s', {
      rate: __ENV.PANEL_RPS || 4,
      preAllocatedVUs: 10,
      maxVUs: 40,
    }, __ENV.PANEL_VUS || 10),
  },
  thresholds: {
    http_req_failed: ['rate<0.05'],
    http_req_duration: ['p(95)<3000'],
    leaderboard_duration: ['p(95)<2500'],
    dashboard_duration: ['p(95)<2500'],
    panel_duration: ['p(95)<4000'],
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

function pickStaff(data) {
  const tokens = data.staffTokens || [];
  if (tokens.length === 0) {
    return { token: data.adminToken, userId: data.adminUserId };
  }

  const index = (__VU - 1) % tokens.length;

  return tokens[index];
}

export function setup() {
  const adminToken = __ENV.ADMIN_TOKEN;
  const staffTokenBlob = __ENV.STAFF_TOKENS || '';

  if (!adminToken) {
    throw new Error('ADMIN_TOKEN wajib diisi. Generate lewat artisan createToken.');
  }

  const staffTokens = staffTokenBlob
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean)
    .map((entry) => {
      const [token, userId] = entry.split(':');

      return { token, userId: Number(userId || 0) };
    });

  const me = http.get(`${baseUrl}/api/v1/auth/me`, {
    headers: apiHeaders(adminToken),
  });
  const meBody = me.json();

  check(me, {
    'setup admin me is 200': (result) => result.status === 200,
  });

  const panelCookies = filamentLogin(__ENV.PANEL_USER || 'admin', __ENV.PANEL_PASSWORD || 'complete123');

  return {
    adminToken,
    adminUserId: meBody?.data?.id || 1,
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

export function staffFlow(data) {
  const staff = pickStaff(data);
  const headers = apiHeaders(staff.token);
  const userId = staff.userId || data.adminUserId;

  group('staff', () => {
    const me = http.get(`${baseUrl}/api/v1/auth/me`, { headers });
    const journals = http.get(`${baseUrl}/api/v1/journals?per_page=15`, { headers });
    const today = http.get(`${baseUrl}/api/v1/journals/today`, { headers });
    const dailies = http.get(`${baseUrl}/api/v1/activities/dailies?per_page=15`, { headers });
    const checklist = http.get(`${baseUrl}/api/v1/analytics/kpi-checklist?user_id=${userId}&periode=${data.period}`, { headers });
    const summary = http.get(`${baseUrl}/api/v1/kpis/user/${userId}/summary?periode=${data.period}`, { headers });
    const weeklies = http.get(`${baseUrl}/api/v1/activities/weeklies?per_page=15`, { headers });
    const monthlies = http.get(`${baseUrl}/api/v1/activities/monthlies?per_page=15`, { headers });

    check(me, { 'staff me 200': (result) => result.status === 200 });
    check(journals, { 'staff journals 200': (result) => result.status === 200 });
    check(today, { 'staff journal today 200': (result) => result.status === 200 });
    check(dailies, { 'staff dailies 200': (result) => result.status === 200 });
    check(checklist, { 'staff checklist 200': (result) => result.status === 200 });
    check(summary, { 'staff summary 200': (result) => result.status === 200 });
    check(weeklies, { 'staff weeklies 200': (result) => result.status === 200 });
    check(monthlies, { 'staff monthlies 200': (result) => result.status === 200 });
  });

  if (thinkSeconds > 0) {
    sleep(thinkSeconds);
  }
}

export function analyticsFlow(data) {
  const headers = apiHeaders(data.adminToken);

  group('analytics', () => {
    const leaderboard = http.get(
      `${baseUrl}/api/v1/analytics/leaderboard?periode=${data.period}&per_page=50`,
      { headers },
    );
    leaderboardDuration.add(leaderboard.timings.duration);

    const dashboard = http.get(
      `${baseUrl}/api/v1/analytics/dashboard?periode=${data.period}`,
      { headers },
    );
    dashboardDuration.add(dashboard.timings.duration);

    const stats = http.get(
      `${baseUrl}/api/v1/analytics/department-stats?periode=${data.period}`,
      { headers },
    );
    const users = http.get(`${baseUrl}/api/v1/users?per_page=25`, { headers });
    const kpis = http.get(`${baseUrl}/api/v1/kpis?per_page=25`, { headers });
    const reviews = http.get(`${baseUrl}/api/v1/employee-reviews?per_page=25`, { headers });
    const attendances = http.get(`${baseUrl}/api/v1/attendances?per_page=25`, { headers });

    check(leaderboard, { 'leaderboard 200': (result) => result.status === 200 });
    check(dashboard, { 'dashboard 200': (result) => result.status === 200 });
    check(stats, { 'department stats 200': (result) => result.status === 200 });
    check(users, { 'users 200': (result) => result.status === 200 });
    check(kpis, { 'admin kpis 200': (result) => result.status === 200 });
    check(reviews, { 'reviews 200': (result) => result.status === 200 });
    check(attendances, { 'attendances 200': (result) => result.status === 200 });
  });

  if (thinkSeconds > 0) {
    sleep(thinkSeconds);
  }
}

export function panelFlow(data) {
  applyCookies(data.panelCookies);

  group('panel', () => {
    const dashboard = http.get(`${baseUrl}/admin`, {
      headers: { Accept: 'text/html' },
      redirects: 0,
    });
    panelDuration.add(dashboard.timings.duration);

    const leaderboardTab = http.get(`${baseUrl}/admin?tab=leaderboard`, {
      headers: { Accept: 'text/html' },
      redirects: 0,
    });

    check(dashboard, {
      'panel dashboard authenticated': (result) => result.status === 200,
    });
    check(leaderboardTab, {
      'panel leaderboard tab authenticated': (result) => result.status === 200,
    });
  });

  if (thinkSeconds > 0) {
    sleep(thinkSeconds);
  }
}
