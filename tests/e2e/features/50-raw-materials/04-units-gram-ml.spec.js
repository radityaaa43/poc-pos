import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const expect = baseExpect.configure({ timeout: 45_000 });

const manager = roles.find((role) => role.key === 'manager');

test.describe('50-raw-materials', () => {
    test.describe.configure({ timeout: 240_000 }); // a loaded runner needs more than the default 30 s
    test.use({ actionTimeout: 45_000 });

    test('units-gram-ml: the unit master offers Gram and Mililiter', { tag: ['@REQ-002-AC-01'] }, async ({ asRole }) => {
        const page = await asRole(manager);

        await page.goto('/dashboard/settings/units');

        // A unit row reads: name, symbol, code.
        await expect(page.locator('div.p-4').filter({ hasText: /^GramgGRAM/ })).toHaveCount(1);
        await expect(page.locator('div.p-4').filter({ hasText: /^MililitermlML/ })).toHaveCount(1);
    });
});
