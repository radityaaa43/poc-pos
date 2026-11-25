import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('25-cashier-shifts', () => {
    // Multi-page flows: the shared 4-CPU machine can take longer than the default 30 s.
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('POS is blocked without an open shift and unlocks once the cashier opens one', async ({ asRole, arrange }) => {
        arrange('posops.no-shift');
        const page = await asRole(cashier);

        await page.goto('/dashboard/transactions');
        await expect(page.getByRole('heading', { name: 'Shift kasir belum dibuka' })).toBeVisible();
        await expect(page.getByPlaceholder(/Cari produk/)).toHaveCount(0);

        // A cart endpoint behind the shift middleware bounces a plain visit back to the POS.
        await page.goto('/dashboard/transactions/held');
        await expect(page).toHaveURL(/\/dashboard\/transactions$/);
        await expect(page.getByRole('heading', { name: 'Shift kasir belum dibuka' })).toBeVisible();

        await page.getByPlaceholder('0').fill('150000');
        await page.getByRole('button', { name: 'Buka Shift Sekarang' }).click();
        await expect(page.getByPlaceholder(/Cari produk/)).toBeVisible();

        // Side effect on the shifts page: the new shift is active with the starting cash.
        await page.goto('/dashboard/cashier-shifts');
        await expect(page.getByText('Shift Aktif', { exact: true })).toBeVisible();
        await expect(page.getByText('Rp 150.000').first()).toBeVisible();
    });
});
