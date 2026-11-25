import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('25-cashier-shifts', () => {
    // Multi-page flows: the shared 4-CPU machine can take longer than the default 30 s.
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('the cashier closes the shift with a counted cash and the POS is locked again', async ({ asRole, arrange }) => {
        const { shift_id } = arrange('open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await page.goto(`/dashboard/cashier-shifts/${shift_id}`);
        await expect(page.getByText('Shift Aktif', { exact: true })).toBeVisible();
        await page.getByRole('spinbutton').fill('95000');
        await expect(page.getByText(/Selisih closing: -Rp\s*5\.000/)).toBeVisible();
        await page.getByRole('button', { name: 'Finalisasi Closing' }).click();

        await expect(page.getByText('Shift Closed', { exact: true })).toBeVisible();
        await expect(page.getByText('Actual Cash').locator('xpath=following-sibling::span')).toHaveText(/Rp\s*95\.000/);
        await expect(page.getByText('Selisih', { exact: true }).locator('xpath=following-sibling::span')).toHaveText(/-Rp\s*5\.000/);
        await expect(page.getByRole('button', { name: 'Finalisasi Closing' })).toHaveCount(0);

        // Side effect: no open shift is left, so the POS asks for a new one.
        await page.goto('/dashboard/transactions');
        await expect(page.getByRole('heading', { name: 'Shift kasir belum dibuka' })).toBeVisible();
    });
});
