import { readFileSync } from 'node:fs';
import { test, expect } from './fixtures.js';
import { roles } from './roles.js';

// Expected outcome per role and page, reviewed by hand: tests/e2e/role-matrix.expected.json.
const matrix = JSON.parse(readFileSync(new URL('../role-matrix.expected.json', import.meta.url), 'utf8'));

const outcome = (status) => ({ 200: 'allowed', [matrix.refused.status]: 'forbidden' })[status] ?? `HTTP ${status}`;

// One step per role: every listed page opens or is refused as the JSON says, and the sidebar shows exactly that menu.
export function roleMatrix(key) {
    const role = roles.find((r) => r.key === key);
    const expected = matrix.roles[key];

    test.describe(`role matrix: ${key}`, () => {
        test('each dashboard page opens or is refused', async ({ asRole }) => {
            test.setTimeout(120_000);
            const page = await asRole(role);

            // Pages are slow on a shared machine: 6 at a time keeps the step well inside its timeout.
            const urls = Object.keys(expected.pages);
            const actual = {};
            for (let i = 0; i < urls.length; i += 6) {
                await Promise.all(urls.slice(i, i + 6).map(async (url) => {
                    // No redirects followed: a refusal must answer in place, not bounce to another page.
                    actual[url] = outcome((await page.request.get(url, { maxRedirects: 0 })).status());
                }));
            }
            expect(actual).toEqual(expected.pages);

            const refused = Object.keys(expected.pages).find((url) => expected.pages[url] === 'forbidden');
            if (refused) {
                const response = await page.goto(refused);
                expect(response.status()).toBe(matrix.refused.status);
                await expect(page).toHaveURL(new RegExp(`${refused}$`));
            }
        });

        test('the sidebar shows exactly the role\'s menu', async ({ asRole }) => {
            const page = await asRole(role);
            await page.goto('/dashboard/access');

            // Links and dropdown buttons, in menu order. The sidebar <nav> has no accessible name.
            const entries = page.locator('nav.dashboard-scrollbar').locator('a, button');
            await expect(entries).toHaveText(expected.sidebar);

            for (const [parent, children] of Object.entries(expected.submenu ?? {})) {
                await page.getByRole('button', { name: parent, exact: true }).click();
                for (const child of children) {
                    await expect(page.getByRole('link', { name: child, exact: true })).toBeVisible();
                }
            }
        });
    });
}
