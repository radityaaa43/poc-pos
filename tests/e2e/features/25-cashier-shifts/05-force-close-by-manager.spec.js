import { test, expect as baseExpect } from '../../support/fixtures.js';
import { login } from '../../support/login.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('25-cashier-shifts', () => {
    // Multi-page flows: the shared 4-CPU machine can take longer than the default 30 s.
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('a manager force closes the cashier shift after confirming their password', async ({ page, arrange }) => {
        const { shift_id } = arrange('open-shift', { cash: 100000 });

        // A fresh login, so the password confirmation of a shared saved session cannot skip the challenge.
        await login(page, 'manager@e2e.test');
        await expect(page).toHaveURL(/\/dashboard\/transactions$/);
        await page.goto(`/dashboard/cashier-shifts/${shift_id}`);
        await page.getByRole('spinbutton').fill('90000');
        await page.getByRole('button', { name: 'Finalisasi Closing' }).click();

        await expect(page).toHaveURL(/confirm-password/);
        await expect(page.getByRole('heading', { name: 'Konfirmasi Password' })).toBeVisible();
        await page.getByPlaceholder('Masukkan password Anda').fill('wrong-password');
        await page.getByRole('button', { name: 'Lanjutkan' }).click();
        await expect(page.getByText(/password.*(incorrect|salah)/i)).toBeVisible();

        await page.getByPlaceholder('Masukkan password Anda').fill('password');
        await page.getByRole('button', { name: 'Lanjutkan' }).click();

        // Back on the shift (the form is empty again): close it for real.
        await expect(page).toHaveURL(new RegExp(`/dashboard/cashier-shifts/${shift_id}$`));
        await page.getByRole('spinbutton').fill('90000');
        await page.getByRole('button', { name: 'Finalisasi Closing' }).click();

        await expect(page.getByText('Force Closed', { exact: true })).toBeVisible();
        await expect(page.getByText('Selisih', { exact: true }).locator('xpath=following-sibling::span')).toHaveText(/-Rp\s*10\.000/);
    });

    test('a cashier cannot open the shift of another user', async ({ asRole, arrange }) => {
        const { shift_id } = arrange('open-shift', { cashier: 'manager@e2e.test', cash: 100000 });
        const page = await asRole(cashier);

        const response = await page.goto(`/dashboard/cashier-shifts/${shift_id}`);
        expect(response.status()).toBe(404);
    });
});
