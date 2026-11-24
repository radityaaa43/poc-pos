import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Copied by `npm run e2e:new`. Each step arranges its own data and must not depend on other steps.
test.describe('__FEATURE__', () => {
    // Tag the acceptance criteria this step proves, e.g. tag: ['@REQ-002-AC-05'] (tests/e2e/README.md).
    test.fixme('__STEP__: replace with the first real step', { tag: [] }, async ({ asRole, arrange, uniq }) => {
        const page = await asRole(roles.find((role) => role.key === 'super-admin'));

        // const { shift_id } = arrange('open-shift', { cash: 100000 });
        // const title = uniq('product');
        await page.goto('/dashboard');
        await expect(page).toHaveURL(/dashboard/);
    });
});
