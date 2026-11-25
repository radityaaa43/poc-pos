import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

test.describe('role matrix: routes without a page', () => {
    // Bug #97: the unit and warehouse create routes have no controller method, so they answer 500.
    test.fixme('the unit and warehouse create routes never answer 500', async ({ asRole }) => {
        const page = await asRole(roles.find((role) => role.key === 'super-admin'));

        for (const url of ['/dashboard/settings/units/create', '/dashboard/settings/warehouses/create']) {
            expect((await page.request.get(url)).status()).toBeLessThan(500);
        }
    });
});
