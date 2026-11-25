import { test, expect as baseExpect } from '../../support/fixtures.js';
import { confirmPassword, signIn } from '../../support/platform.js';

// Pages here are slow to answer on a shared machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 45_000 });
test.use({ actionTimeout: 60_000, navigationTimeout: 60_000 });

// Payment settings (gateways, bank accounts) decide which methods checkout offers: each step puts back what it changed.
test.describe('payment settings', () => {
    test.setTimeout(240_000);

    test('the gateway keys save, secrets stay masked, and Midtrans needs both keys when it is on', async ({ page, arrange, uniq }) => {
        const admin = arrange('platform.user', { role: 'super-admin' });
        const clientKey = page.getByPlaceholder('SB-Mid-client-xxx');
        const clientKeyValue = `SB-Mid-client-${uniq('k')}`;
        const save = async () => {
            const saved = page.waitForResponse((response) => response.url().endsWith('/settings/payments') && response.request().method() === 'PUT');
            await page.getByRole('button', { name: 'Simpan Konfigurasi' }).click();
            await saved;
        };

        await signIn(page, admin.email);
        await confirmPassword(page);
        await page.goto('/dashboard/settings/payments');
        const original = await clientKey.inputValue();

        // The saved server key is only shown masked, never in a field.
        await expect(page.getByText(/Tersimpan: •+-e2e/).first()).toBeVisible();

        try {
            // Midtrans is on: an empty client key is refused.
            await clientKey.fill('');
            await save();
            await expect(page.getByText('Server key dan Client key Midtrans wajib diisi saat mengaktifkan Midtrans.')).toBeVisible();

            await clientKey.fill(clientKeyValue);
            await save();
            await page.reload();
            await expect(clientKey).toHaveValue(clientKeyValue);
            await expect(page.getByText(/Tersimpan: •+-e2e/).first()).toBeVisible();
        } finally {
            await page.goto('/dashboard/settings/payments');
            await clientKey.fill(original);
            await save();
        }
    });

    test('a bank account is added, switched off, edited and deleted', async ({ page, arrange, uniq }) => {
        const admin = arrange('platform.user', { role: 'super-admin' });
        const bank = uniq('Bank');
        const row = (title) => page.locator('div.p-4.flex').filter({ hasText: title });

        await signIn(page, admin.email);
        await confirmPassword(page);
        await page.goto('/dashboard/settings/bank-accounts');
        await page.getByRole('link', { name: 'Tambah Bank' }).click();

        await page.getByRole('button', { name: 'Simpan' }).click();
        await expect(page.getByText('The bank name field is required.')).toBeVisible();
        await expect(page.getByText('The account number field is required.')).toBeVisible();
        await expect(page.getByText('The account name field is required.')).toBeVisible();

        await page.getByPlaceholder('BCA, Mandiri, BNI...').fill(bank);
        await page.getByPlaceholder('1234567890').fill('9876543210');
        await page.getByPlaceholder('Nama pemilik rekening').fill('PT Uji E2E');
        await page.getByRole('button', { name: 'Simpan' }).click();
        await expect(page).toHaveURL(/\/settings\/bank-accounts$/);
        await expect(page.getByText(bank, { exact: true })).toBeVisible();
        await expect(page.getByText('9876543210')).toBeVisible();

        // Switch it off and on again.
        await page.goto('/dashboard/settings/bank-accounts');
        await row(bank).getByRole('button', { name: 'Aktif' }).click();
        await expect(row(bank).getByRole('button', { name: 'Nonaktif' })).toBeVisible();
        await row(bank).getByRole('button', { name: 'Nonaktif' }).click();
        await expect(row(bank).getByRole('button', { name: 'Aktif' })).toBeVisible();

        // Edit the holder, then delete the account.
        await page.goto('/dashboard/settings/bank-accounts');
        await row(bank).getByRole('link').click();
        await page.getByPlaceholder('Nama pemilik rekening').fill('PT Uji E2E Baru');
        await page.getByRole('button', { name: 'Update' }).click();
        await expect(page.getByText('PT Uji E2E Baru')).toBeVisible();

        page.once('dialog', (dialog) => dialog.accept());
        await row(bank).getByRole('button').last().click();
        await expect(page.getByText(bank, { exact: true })).toBeHidden();
    });
});
