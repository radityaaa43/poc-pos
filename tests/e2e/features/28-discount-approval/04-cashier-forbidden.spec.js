import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('28-discount-approval', () => {
    // Multi-page flows: the shared 4-CPU machine can take longer than the default 30 s.
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('a cashier cannot open the approvals page', async ({ asRole, arrange }) => {
        arrange('posops.pending-discount');
        const page = await asRole(cashier);

        const response = await page.goto('/dashboard/discount-approvals');
        expect(response.status()).toBe(403);
        await expect(page.getByRole('button', { name: 'Setujui' })).toHaveCount(0);
    });
});
