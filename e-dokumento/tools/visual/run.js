// Visual checks: starts a fake Supabase and the PHP dev server, opens every page as each role in
// dark and light, at desktop and phone width, saves screenshots to tools/visual/out/, and fails on
// sideways scrolling, console errors or error pages.
//
//   node run.js            all roles, both themes, both sizes
//   node run.js --quick    dark theme only
//
// Needs: PHP 8.1+ (PHP_BIN), Python 3 (PYTHON), Google Chrome, and `npm install` in this folder.
'use strict';
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const http = require('http');
const path = require('path');

const APP = path.resolve(__dirname, '..', '..');
const OUT = path.join(__dirname, 'out');
const PHP = process.env.PHP_BIN || 'php';
const PY = process.env.PYTHON || 'python';
const APP_PORT = Number(process.env.APP_PORT || 8124);
const FAKE_PORT = Number(process.env.FAKE_PORT || 8125);
const BASE = `http://127.0.0.1:${APP_PORT}`;
const quick = process.argv.includes('--quick');

const uuid = (n) => '00000000-0000-4000-8000-' + String(n).padStart(12, '0');
const ROLE_ID = { admin: 1, captain: 2, secretary: 3, treasurer: 4, resident: 5 };
const REQ = (n) => '/requests/view?id=' + uuid(300 + n);

// Page lists per role. `status` is the expected HTTP status (default 200).
const PAGES = {
  guest: [['login', '/login'], ['register', '/register'], ['forgot', '/forgot-password'], ['reset', '/reset-password'], ['verify', '/verify'], ['verify-result', '/verify?code=A1B2C3D4E5'], ['not-found', '/nope', 404]],
  resident: [['dashboard', '/dashboard'], ['requests', '/requests'], ['new-request', '/requests/new'], ['request-pending', REQ(0)], ['request-ready', REQ(5)], ['request-rejected', REQ(7)], ['profile', '/profile'], ['notifications', '/notifications']],
  secretary: [['dashboard', '/dashboard'], ['requests', '/requests'], ['request-review', REQ(1)], ['walk-in', '/requests/new'], ['residents', '/residents'], ['resident', '/residents/view?id=' + uuid(101)], ['resident-form', '/residents/form'], ['verifications', '/verifications'], ['documents', '/documents'], ['certificate', '/documents/print?id=' + uuid(705)], ['reports', '/reports']],
  treasurer: [['dashboard', '/dashboard'], ['cashiering', '/payments'], ['payment-history', '/payments?tab=history'], ['payment', '/payments/view?id=' + uuid(503)], ['reports', '/reports']],
  captain: [['dashboard', '/dashboard'], ['approvals', '/requests?status=for_approval'], ['audit', '/audit']],
  admin: [['dashboard', '/dashboard'], ['document-types', '/document-types'], ['document-type-form', '/document-types/form'], ['requirements', '/requirements'], ['reference', '/reference'], ['officials', '/officials'], ['users', '/users'], ['settings', '/settings'], ['audit', '/audit']],
};

const b64 = (o) => Buffer.from(JSON.stringify(o)).toString('base64url');
const token = (role) => b64({ alg: 'none' }) + '.' + b64({ sub: uuid(ROLE_ID[role]), exp: Math.floor(Date.now() / 1000) + 36000 }) + '.sig';

function waitFor(url, ms = 15000) {
  const end = Date.now() + ms;
  return new Promise((resolve, reject) => {
    const tryOnce = () => http.get(url, (res) => { res.resume(); resolve(); })
      .on('error', () => (Date.now() > end ? reject(new Error('Timed out waiting for ' + url)) : setTimeout(tryOnce, 250)));
    tryOnce();
  });
}

(async () => {
  let version;
  try { version = Number(execFileSync(PHP, ['-r', 'echo PHP_VERSION_ID;']).toString()); } catch (e) { console.error(`Cannot run "${PHP}". Set PHP_BIN to a PHP 8.1+ binary.`); process.exit(2); }
  if (version < 80100) { console.error(`PHP 8.1 or newer is required (found ${version}). Set PHP_BIN.`); process.exit(2); }
  const { chromium } = require('playwright-core');

  const env = {
    ...process.env, FAKE_PORT: String(FAKE_PORT), SUPABASE_URL: `http://127.0.0.1:${FAKE_PORT}`, SUPABASE_PUBLISHABLE_KEY: 'sb_publishable_fake',
    SUPABASE_SECRET_KEY: 'sb_secret_fake', APP_URL: BASE, APP_SECRET: '0123456789abcdef'.repeat(4), APP_DEBUG: 'true',
  };
  const children = [
    spawn(PY, [path.join(__dirname, 'fake_supabase.py')], { env, stdio: 'ignore' }),
    spawn(PHP, ['-S', `127.0.0.1:${APP_PORT}`, 'router.php'], { cwd: APP, env, stdio: 'ignore' }),
  ];
  const stop = () => children.forEach((c) => { try { c.kill(); } catch (e) { /* already gone */ } });
  process.on('exit', stop);
  process.on('SIGINT', () => { stop(); process.exit(130); });

  try {
    await waitFor(`${BASE}/login`);
    fs.rmSync(OUT, { recursive: true, force: true });
    const browser = await chromium.launch({ channel: process.env.PW_CHANNEL || 'chrome', headless: true });
    const themes = quick ? ['dark'] : ['dark', 'light'];
    const sizes = { desktop: { width: 1440, height: 900 }, phone: { width: 390, height: 844 } };
    let shots = 0;
    const problems = [];
    for (const [role, pages] of Object.entries(PAGES)) {
      for (const theme of themes) {
        for (const [sizeName, viewport] of Object.entries(sizes)) {
          const ctx = await browser.newContext({ viewport, isMobile: sizeName === 'phone', hasTouch: sizeName === 'phone' });
          if (role !== 'guest') await ctx.addCookies([{ name: 'edk_at', value: token(role), url: BASE }, { name: 'edk_rt', value: 'rt-' + role, url: BASE }]);
          await ctx.addInitScript((t) => { try { localStorage.setItem('edk_theme_v2', t); } catch (e) { /* ignore */ } }, theme);
          const page = await ctx.newPage();
          const errors = [];
          page.on('pageerror', (e) => errors.push(e.message));
          page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
          for (const [name, url, expected = 200] of pages) {
            errors.length = 0;
            const res = await page.goto(BASE + url, { waitUntil: 'networkidle' });
            await page.waitForTimeout(400);
            const dir = path.join(OUT, `${theme}-${sizeName}`, role);
            fs.mkdirSync(dir, { recursive: true });
            await page.screenshot({ path: path.join(dir, name + '.png'), fullPage: true });
            shots++;
            const label = `${role}/${name} (${theme}, ${sizeName})`;
            if (res.status() !== expected) problems.push(`${label}: HTTP ${res.status()}, expected ${expected}`);
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
            if (overflow > 1) problems.push(`${label}: scrolls sideways by ${overflow}px`);
            const real = errors.filter((e) => !(expected === 404 && /404/.test(e)));
            if (real.length) problems.push(`${label}: console error: ${real[0]}`);
          }
          await ctx.close();
        }
      }
    }
    await browser.close();
    console.log(`${shots} screenshots in ${path.relative(process.cwd(), OUT) || OUT}`);
    if (problems.length) { console.error('\nProblems:\n - ' + problems.join('\n - ')); process.exitCode = 1; } else { console.log('No problems found.'); }
  } finally {
    stop();
  }
})().catch((e) => { console.error(e.message); process.exit(1); });
