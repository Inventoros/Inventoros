import { existsSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';

const dir = process.argv[2] || 'audit-artifacts';
if (!existsSync(dir)) {
    process.stdout.write('No rendered audit evidence was produced.\n');
    process.exit(0);
}
const files = readdirSync(dir);
const pages = files.filter(file => file.endsWith('.json') && file !== 'summary.json')
    .map(file => JSON.parse(readFileSync(path.join(dir, file), 'utf8')))
    .filter(page => typeof page.name === 'string' && typeof page.width === 'number');
const countBy = key => Object.fromEntries([...new Set(pages.map(key))].sort()
    .map(value => [value, pages.filter(page => key(page) === value).length]));
const unexpectedHttp = pages.filter(page => page.status >= 400 && !(page.name === 'permission-denied' && page.status === 403));
const findings = pages.flatMap(page => page.violations.map(violation => ({ name: page.name, width: page.width, rule: violation.id, impact: violation.impact, nodes: violation.nodes.length })));
const summary = {
    checks: pages.length,
    screenshots: files.filter(file => file.endsWith('.png')).length,
    widths: countBy(page => page.width),
    languages: countBy(page => page.lang),
    axeChecked: pages.filter(page => page.axeChecked).length,
    unexpectedHttp: unexpectedHttp.map(({ name, width, status }) => ({ name, width, status })),
    javascriptExceptions: pages.filter(page => page.errors.length).map(({ name, width, errors }) => ({ name, width, errors })),
    documentOverflows: pages.filter(page => page.overflow > 1).map(({ name, width, overflow }) => ({ name, width, overflow })),
    accessibilityFindings: findings,
};
writeFileSync(path.join(dir, 'summary.json'), JSON.stringify(summary, null, 2) + '\n');
process.stdout.write(`Rendered checks: ${summary.checks}; screenshots: ${summary.screenshots}; unexpected HTTP: ${unexpectedHttp.length}; JS exceptions: ${summary.javascriptExceptions.length}; document overflows: ${summary.documentOverflows.length}; accessibility rule findings: ${findings.length}.\n`);
