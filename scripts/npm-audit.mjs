// CI's npm audit (see .github/workflows/tests.yml, dependency-audit job).
//
// Fails on every advisory in the locked dependency tree, like
// `npm audit --package-lock-only`, except the ones listed in ACCEPTED below.
// An accepted advisory must name the package, say why it does not affect
// Inventoros, and carry an expiry date: after that date it fails again, so
// it is looked at once more (has a fix shipped?) instead of lingering.

import { execFileSync } from 'node:child_process';

const ACCEPTED = [
    {
        id: 'GHSA-fc5q-39xc-vjpf',
        package: 'picomatch',
        // No fixed release exists (every version up to 4.0.7 is affected).
        // picomatch is only used by the build tooling (vite, tinyglobby and
        // vite-plugin-full-reload via laravel-vite-plugin) to match the
        // project's own file globs at build time. It never sees user input
        // and is not part of the application that runs on a server.
        reason: 'build-time only; globs come from the repository, not users; no patched version yet',
        expires: '2027-01-31',
    },
];

function audit() {
    try {
        return execFileSync('npm', ['audit', '--package-lock-only', '--json'], { encoding: 'utf8', shell: process.platform === 'win32' });
    } catch (error) {
        // npm audit exits non-zero when it finds anything; the JSON is still on stdout.
        if (error.stdout) return error.stdout;
        throw error;
    }
}

const report = JSON.parse(audit());
const today = new Date().toISOString().slice(0, 10);
const advisories = new Map();

for (const vulnerability of Object.values(report.vulnerabilities ?? {})) {
    for (const via of vulnerability.via ?? []) {
        if (typeof via !== 'object' || via === null) continue;
        const id = String(via.url ?? '').split('/').pop() || String(via.source);
        advisories.set(`${via.name}:${id}`, { id, name: via.name, title: via.title, severity: via.severity, url: via.url });
    }
}

const failures = [];
const accepted = [];

for (const advisory of advisories.values()) {
    const rule = ACCEPTED.find((entry) => entry.id === advisory.id && entry.package === advisory.name);
    if (!rule) {
        failures.push(advisory);
    } else if (rule.expires < today) {
        failures.push({ ...advisory, title: `${advisory.title} (accepted until ${rule.expires}, expired: re-check whether a fix has shipped)` });
    } else {
        accepted.push({ ...advisory, rule });
    }
}

for (const { id, name, severity, rule } of accepted) {
    console.log(`accepted  ${severity.padEnd(8)} ${name} ${id} until ${rule.expires}: ${rule.reason}`);
}

if (failures.length > 0) {
    for (const { id, name, severity, title, url } of failures) {
        console.error(`FAIL      ${String(severity).padEnd(8)} ${name} ${id}: ${title} ${url ?? ''}`);
    }
    process.exit(1);
}

console.log(`npm audit: no advisories outside the accepted list (${accepted.length} accepted).`);
