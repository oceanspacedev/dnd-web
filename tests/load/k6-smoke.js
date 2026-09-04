import http from 'k6/http';
import { check } from 'k6';

const baseUrl = (__ENV.BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const targetPath = __ENV.TARGET_PATH || '/up';

export const options = {
  discardResponseBodies: true,
  scenarios: {
    steady_rps: {
      executor: 'constant-arrival-rate',
      rate: Number(__ENV.RPS || 50),
      timeUnit: '1s',
      duration: __ENV.DURATION || '30s',
      preAllocatedVUs: Number(__ENV.PRE_ALLOCATED_VUS || 20),
      maxVUs: Number(__ENV.MAX_VUS || 200),
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<1000'],
  },
};

export default function () {
  const headers = {
    Accept: 'application/json',
  };

  if (__ENV.BEARER_TOKEN) {
    headers.Authorization = `Bearer ${__ENV.BEARER_TOKEN}`;
  }

  const response = http.get(`${baseUrl}${targetPath}`, { headers });

  check(response, {
    'status is below 400': (result) => result.status >= 200 && result.status < 400,
  });
}
