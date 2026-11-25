import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('25-cashier-shifts', () => {
    // Multi-page flows: the shared 4-CPU machine can take longer than the default 30 s.
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('opening a shift while one is already open keeps the first shift', async ({ asRole, arrange }) => {
        arrange('posops.no-shift');
        const page = await asRole(cashier);

        await page.goto('/dashboard/cashier-shifts');
        await expect(page.getByRole('heading', { name: 'Buka Shift Baru' })).toBeVisible();

        // The same cashier opens a shift elsewhere (a second device) while this form is still on screen.
        arrange('open-shift', { cash: 50000 });
        await page.getByPlaceholder('0').fill('10000');
        await page.getByRole('button', { name: 'Buka Shift', exact: true }).click();

        // The server refuses ("Kasir ini masih memiliki shift aktif."): one shift stays open, with the first cash.
        await expect(page.getByRole('heading', { name: 'Buka Shift Baru' })).toHaveCount(0);
        await expect(page.getByText('Shift Aktif', { exact: true })).toBeVisible();
        await expect(page.getByText('Rp 50.000').first()).toBeVisible();
        await expect(page.getByRole('row').filter({ hasText: 'Open' })).toHaveCount(1);
    });
});
