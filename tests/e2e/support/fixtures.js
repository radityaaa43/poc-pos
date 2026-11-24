import { test as base, expect } from '@playwright/test';
import { arrange } from './arrange.js';
import { authFile } from './roles.js';

export { expect };

// The UI picks its language from localStorage first, then the browser (Chromium is en-US).
const forceIndonesian = (context) => context.addInitScript(() => localStorage.setItem('i18nextLng', 'id'));

export const test = base.extend({
    context: async ({ context }, use) => {
        await forceIndonesian(context);
        await use(context);
    },

    // A page already logged in as the given role (role object from roles.js).
    asRole: async ({ browser }, use) => {
        const contexts = [];
        await use(async (role) => {
            const context = await browser.newContext({ storageState: authFile(role), locale: 'id-ID', serviceWorkers: 'block' });
            await forceIndonesian(context);
            contexts.push(context);

            return context.newPage();
        });
        await Promise.all(contexts.map((context) => context.close()));
    },

    arrange: async ({}, use) => use(arrange),

    // Unique, readable value for data a step creates, so reruns and shards never collide.
    uniq: async ({}, use) => use((prefix) => `${prefix}-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`),
});
