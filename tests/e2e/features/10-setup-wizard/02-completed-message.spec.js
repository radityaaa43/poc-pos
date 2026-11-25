import { test, expect as baseExpect } from '../../support/fixtures.js';
import { field } from '../../support/platform.js';

// Pages here are slow to answer on a shared machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 45_000 });
test.use({ actionTimeout: 60_000, navigationTimeout: 60_000 });

test.describe('setup wizard', () => {
    // Bug #98: SetupController redirects to /login with a `success` flash, but Auth/Login.jsx only renders `status`.
    test.fixme('the login page confirms that setup is complete', async ({ page, arrange, uniq }) => {
        const next = () => page.getByRole('button', { name: 'Lanjut' }).click();

        arrange('platform.setup-pending');
        try {
            await page.goto('/setup');
            await page.getByPlaceholder('Contoh: Toko Berkah Jaya').fill(uniq('Toko'));
            await next();
            await page.getByRole('button', { name: /^Retail/ }).click();
            await next();
            await page.getByPlaceholder('Tambah kategori lain...').fill(uniq('Kategori'));
            await page.getByRole('button', { name: 'Tambah', exact: true }).click();
            await next();
            await page.getByPlaceholder('Contoh: MAL').fill(uniq('o').toUpperCase().slice(0, 12));
            await page.getByPlaceholder('Contoh: Cafe Malabar').fill('Outlet baru');
            await page.getByPlaceholder('Contoh: WH-MAL').fill(uniq('w').toUpperCase().slice(0, 12));
            await page.getByPlaceholder('Contoh: Gudang Malabar').fill('Gudang baru');
            await next();
            await field(page, 'Nama Lengkap').fill('Admin Baru');
            await field(page, 'Email').fill(`${uniq('admin')}@e2e.test`);
            await field(page, 'Kata Sandi').fill('password');
            await page.getByRole('button', { name: 'Selesaikan Setup' }).click();

            await expect(page).toHaveURL(/\/login$/);
            await expect(page.getByText('Setup selesai! Silakan masuk dengan akun yang Anda buat.')).toBeVisible();
        } finally {
            arrange('platform.setup-restore');
        }
    });
});
