import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('25-cashier-shifts', () => {
    // Multi-page flows: the shared 4-CPU machine can take longer than the default 30 s.
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('cash in and cash out change the expected cash of the open shift', async ({ asRole, arrange }) => {
        const { shift_id } = arrange('open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await page.goto('/dashboard/cashier-shifts');
        await page.getByRole('button', { name: 'Kas Masuk' }).click();
        await expect(page.getByRole('heading', { name: 'Catat Kas Masuk' })).toBeVisible();
        await page.getByRole('spinbutton').fill('25000');
        await page.getByPlaceholder(/setoran tambahan modal/).fill('Tambahan modal');
        await page.getByRole('button', { name: 'Simpan' }).click();
        await expect(page.getByRole('heading', { name: 'Catat Kas Masuk' })).toHaveCount(0);
        await expect(page.getByText('Rp 125.000').first()).toBeVisible();

        await page.getByRole('button', { name: 'Kas Keluar' }).click();
        await expect(page.getByRole('heading', { name: 'Catat Kas Keluar' })).toBeVisible();
        await page.getByRole('spinbutton').fill('10000');
        await page.getByRole('button', { name: 'Simpan' }).click();
        await expect(page.getByRole('heading', { name: 'Catat Kas Keluar' })).toHaveCount(0);

        // 100.000 + 25.000 - 10.000, also on the shift detail page.
        await expect(page.getByText('Rp 115.000').first()).toBeVisible();
        await page.goto(`/dashboard/cashier-shifts/${shift_id}`);
        await expect(page.getByText('Rp 115.000').first()).toBeVisible();
    });
});
