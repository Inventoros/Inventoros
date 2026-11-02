import { defineConfig } from '@playwright/test';
import base from './playwright.config';

const chromium = base.projects!.find(project => project.name === 'chromium')!;
const setup = base.projects!.find(project => project.name === 'setup')!;

// Read-only viewport/theme checks may run together. Locale, plugin and
// installer mutations start only after those checks finish, then share one
// sequential file. The ordinary functional e2e configuration stays unchanged.
export default defineConfig({
    ...base,
    workers: 4,
    projects: [
        setup,
        {
            name: 'audit-readonly',
            testMatch: '**/ui-ux-audit.spec.ts',
            grep: /staff route inventory|dark theme/,
            use: chromium.use,
            dependencies: ['setup'],
        },
        {
            name: 'audit-states',
            testMatch: '**/ui-ux-audit.spec.ts',
            grepInvert: /staff route inventory|dark theme/,
            fullyParallel: false,
            use: chromium.use,
            dependencies: ['audit-readonly'],
        },
    ],
});
