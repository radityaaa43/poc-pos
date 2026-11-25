import { test, expect as baseExpect } from '../../support/fixtures.js';
import { login } from '../../support/login.js';
import { field } from '../../support/platform.js';

// Pages here are slow to answer on a shared machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 45_000 });
test.use({ actionTimeout: 60_000, navigationTimeout: 60_000 });

// The wizard is locked once `app_setup_completed` is set, so this step makes the app look freshly
// installed (platform.setup-pending), runs the wizard, and always puts the baseline back
// (platform.setup-restore) so later steps and other groups still get the seeded data.
test.describe('setup wizard', () => {
    test.setTimeout(240_000);
    test('a fresh install walks the five steps, creates the admin, then locks itself', async ({ page, arrange, uniq }) => {
        const store = uniq('Toko');
        const category = uniq('Kategori');
        const outletCode = uniq('o').toUpperCase().slice(0, 12);
        const warehouseCode = uniq('w').toUpperCase().slice(0, 12);
        const email = `${uniq('admin')}@e2e.test`;
        const next = () => page.getByRole('button', { name: 'Lanjut' }).click();

        arrange('platform.setup-pending');
        try {
            // Anyone who opens the app is sent to the wizard.
            await page.goto('/');
            await expect(page).toHaveURL(/\/setup$/);
            await expect(page.getByText('Langkah 1 dari 5')).toBeVisible();

            // Step 1: the store name is required.
            await next();
            await expect(page.getByText('Bagian ini wajib diisi')).toBeVisible();
            await page.getByPlaceholder('Contoh: Toko Berkah Jaya').fill(store);
            await field(page, 'Telepon').fill('081234567890');
            await next();

            // Step 2: pick a business type; step 3: at least one category.
            await expect(page.getByText('Langkah 2 dari 5')).toBeVisible();
            await next();
            await expect(page.getByText('Bagian ini wajib diisi')).toBeVisible();
            await page.getByRole('button', { name: /^Retail/ }).click();
            await next();
            await next();
            await expect(page.getByText('Bagian ini wajib diisi')).toBeVisible();
            await page.getByPlaceholder('Tambah kategori lain...').fill(category);
            await page.getByRole('button', { name: 'Tambah', exact: true }).click();
            await expect(page.getByRole('button', { name: category })).toBeVisible();
            await next();

            // Step 4: a branch needs its codes, and two branches cannot share one.
            await expect(page.getByText('Langkah 4 dari 5')).toBeVisible();
            await next();
            await expect(page.getByText('Bagian ini wajib diisi').first()).toBeVisible();
            await page.getByPlaceholder('Contoh: MAL').fill(outletCode);
            await page.getByPlaceholder('Contoh: Cafe Malabar').fill(`Outlet ${store}`);
            await page.getByPlaceholder('Contoh: WH-MAL').fill(warehouseCode);
            await page.getByPlaceholder('Contoh: Gudang Malabar').fill(`Gudang ${store}`);
            await page.getByRole('button', { name: 'Tambah Cabang' }).click();
            await page.getByPlaceholder('Contoh: MAL').nth(1).fill(outletCode);
            await page.getByPlaceholder('Contoh: Cafe Malabar').nth(1).fill('Cabang kedua');
            await page.getByPlaceholder('Contoh: WH-MAL').nth(1).fill(`${warehouseCode}2`);
            await page.getByPlaceholder('Contoh: Gudang Malabar').nth(1).fill('Gudang kedua');
            await next();
            await expect(page.getByText('Kode sudah digunakan')).toBeVisible();
            await page.getByRole('button', { name: 'Hapus cabang' }).last().click();
            await next();

            // Step 5: a short password is refused by the server and the form stays on this step.
            await expect(page.getByText('Langkah 5 dari 5')).toBeVisible();
            await expect(field(page, 'Kode Gudang Utama')).toHaveValue('PUSAT');
            await field(page, 'Nama Lengkap').fill('Admin Baru');
            await field(page, 'Email').fill(email);
            await field(page, 'Kata Sandi').fill('short');
            await page.getByRole('button', { name: 'Selesaikan Setup' }).click();
            await expect(page.getByText(/at least 8|minimal 8/i).first()).toBeVisible();
            await expect(page).toHaveURL(/\/setup$/);

            await field(page, 'Kata Sandi').fill('password');
            await page.getByRole('button', { name: 'Selesaikan Setup' }).click();

            // Done: back at the login page, the wizard is closed, and the new admin signs in.
            await expect(page).toHaveURL(/\/login$/);
            // The "Setup selesai" message is not shown on the login page: see 02-completed-message (bug #98).
            await page.goto('/setup');
            await expect(page).toHaveURL(/\/login$/);

            await login(page, email);
            await expect(page).toHaveURL(/\/dashboard/);
            await page.goto('/dashboard/settings/store');
            await expect(page.getByPlaceholder('Nama toko')).toHaveValue(store);
            await page.goto('/dashboard/settings/outlets');
            await expect(page.getByRole('heading', { name: `Outlet ${store}` })).toBeVisible();
        } finally {
            arrange('platform.setup-restore');
        }
    });
});
