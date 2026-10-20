// End-to-end smoke check across three accounts (BOT/SOFTWARE, ATC, RDC).
// Run: node scripts/e2e/run.mjs
// Env: E2E_BASE_URL (default http://localhost:8001)
//
// Reports observable behaviour only: HTTP status per page, console errors,
// failed requests, and whether each role can reach what its permission set
// says it should. Exits non-zero when a finding is recorded.

import { chromium } from 'playwright';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const BASE = process.env.E2E_BASE_URL ?? 'http://localhost:8001';
const OUT = 'scripts/e2e/artifacts';
mkdirSync(OUT, { recursive: true });

const ACCOUNTS = [
    { label: 'BOT', kode: 'BOT', password: process.env.E2E_BOT_PASSWORD ?? 'Admin123', role: 'SOFTWARE' },
    { label: 'ATC', kode: 'ATC', password: process.env.E2E_ATC_PASSWORD ?? 'Uji12345', role: 'ATC' },
    { label: 'RDC', kode: 'RDC', password: process.env.E2E_RDC_PASSWORD ?? 'Uji12345', role: 'RDC' },
];

// Pages each role is expected to reach, derived from its permission set.
const PAGES = [
    { path: '/assistant', label: 'Dashboard', perm: null },
    { path: '/modul', label: 'Modul', perm: 'manage-modul' },
    { path: '/soal', label: 'Soal', perm: 'manage-soal' },
    { path: '/praktikum', label: 'Praktikum', perm: 'manage-praktikum' },
    { path: '/praktikan', label: 'Praktikan', perm: 'set-praktikan' },
    { path: '/nilai-praktikan', label: 'Nilai', perm: 'nilai-praktikan' },
    { path: '/plottingan', label: 'Plotting', perm: 'see-plot' },
];

// Which role may open which page, per its permission set in the database.
const GRANTS = {
    SOFTWARE: { 'manage-modul': true, 'manage-soal': true, 'manage-praktikum': true, 'set-praktikan': true, 'nilai-praktikan': true, 'see-plot': true },
    ATC: { 'manage-modul': true, 'manage-soal': true, 'manage-praktikum': false, 'set-praktikan': true, 'nilai-praktikan': true, 'see-plot': true },
    RDC: { 'manage-modul': false, 'manage-soal': false, 'manage-praktikum': true, 'set-praktikan': true, 'nilai-praktikan': true, 'see-plot': true },
};

const findings = [];
const record = (account, kind, detail) => {
    findings.push({ account, kind, detail });
    console.log(`  [${kind}] ${detail}`);
};

async function login(context, account) {
    const page = await context.newPage();
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
    await page.locator('button:has-text("Asisten")').first().click();
    await page.waitForTimeout(800);
    await page.locator('input[name="kode"]').fill(account.kode);
    await page.locator('input[type="password"]').fill(account.password);
    await Promise.all([
        page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30000 }),
        page.locator('button:has-text("Masuk")').click(),
    ]);
    return page;
}

const browser = await chromium.launch();

for (const account of ACCOUNTS) {
    console.log(`\n=== ${account.label} (${account.role}) ===`);
    const context = await browser.newContext();
    const page = await login(context, account);
    console.log(`  login -> ${new URL(page.url()).pathname}`);

    const consoleErrors = [];
    page.on('console', (m) => {
        if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 200));
    });
    page.on('pageerror', (e) => consoleErrors.push('pageerror: ' + e.message.slice(0, 200)));
    page.on('requestfailed', (r) => {
        const err = r.failure()?.errorText ?? '';
        if (err.includes('ECONNREFUSED') || err.includes('ERR_FAILED')) {
            record(account.label, 'NETWORK', `${r.method()} ${r.url().replace(BASE, '')} ${err}`);
        }
    });

    for (const p of PAGES) {
        const res = await page.goto(BASE + p.path, { waitUntil: 'domcontentloaded' }).catch(() => null);
        const status = res?.status() ?? 0;
        const body = await page.locator('body').innerText().catch(() => '');
        const forbidden = /403|Forbidden|Unauthorized|tidak memiliki akses/i.test(body);
        const shouldAllow = !p.perm || GRANTS[account.role]?.[p.perm] === true;
        const landed = new URL(page.url()).pathname;
        const bounced = landed !== p.path;
        const verdict = bounced
            ? `REDIRECT ${landed}`
            : status === 200 && !forbidden
              ? 'ALLOWED'
              : status === 403 || forbidden
                ? 'BLOCKED'
                : `HTTP ${status}`;
        const mark = shouldAllow === (verdict === 'ALLOWED') ? 'ok ' : bounced ? '.. ' : '!! ';
        console.log(`  ${mark}${p.label.padEnd(12)} ${String(status).padEnd(4)} ${verdict}`);
        if (!bounced && shouldAllow !== (verdict === 'ALLOWED')) {
            record(
                account.label,
                'ACCESS',
                `${p.label}: expected ${shouldAllow ? 'allowed' : 'blocked'}, got ${verdict}`,
            );
        }
    }

    if (consoleErrors.length) {
        record(account.label, 'CONSOLE', `${consoleErrors.length} error(s): ${consoleErrors[0]}`);
    }

    await page.screenshot({ path: join(OUT, `${account.label}-dashboard.png`), fullPage: true });
    await context.close();
}

await browser.close();
writeFileSync(join(OUT, 'findings.json'), JSON.stringify(findings, null, 2));
console.log(`\n=== ${findings.length} temuan ===`);
for (const f of findings) console.log(` - [${f.account}/${f.kind}] ${f.detail}`);
process.exit(findings.length ? 1 : 0);
