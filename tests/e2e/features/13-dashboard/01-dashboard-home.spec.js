import { test, expect as baseExpect } from '../../support/fixtures.js';
import { signIn } from '../../support/platform.js';

// Pages here are slow to answer on a shared machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 45_000 });
test.use({ actionTimeout: 60_000, navigationTimeout: 60_000 });

test.describe('dashboard home', () => {
    test.setTimeout(240_000);

    test('the home page shows the day at a glance and flags low stock', async ({ page, arrange, uniq }) => {
        const title = uniq('Menipis');
        const { product_id } = arrange('platform.low-stock-product', { title });
        const { email } = arrange('platform.user');

        try {
            await signIn(page, email);
            await page.goto('/dashboard');

            await expect(page.getByRole('heading', { name: 'Dashboard', level: 1 }).last()).toBeVisible();
            for (const card of ['Penjualan Hari Ini', 'Profit Hari Ini', 'Target Bulan Ini', 'Transaksi Hari Ini', 'Total Produk']) {
                await expect(page.getByText(card, { exact: true })).toBeVisible();
            }
            await expect(page.getByRole('heading', { name: 'Tren Pendapatan' })).toBeVisible();

            // The product under its minimum shows in the low-stock widget.
            await expect(page.getByRole('heading', { name: 'Stok Menipis' })).toBeVisible();
            await expect(page.getByText(title).first()).toBeVisible();

            // The shortcut opens the POS.
            await page.getByRole('link', { name: 'Transaksi Baru' }).click();
            await expect(page).toHaveURL(/\/dashboard\/transactions/);
        } finally {
            arrange('platform.drop-product', { product_id });
        }
    });

    // Bug #100: the subtitle shows the raw ":done" and ":total" placeholders.
    test.fixme('the setup checklist counts the finished steps', async ({ page, arrange }) => {
        const { email } = arrange('platform.user');
        await signIn(page, email);
        await page.goto('/dashboard');

        await expect(page.getByRole('heading', { name: 'Checklist Persiapan Toko' })).toBeVisible();
        await expect(page.getByText(/^\d+ dari \d+ langkah selesai/)).toBeVisible();
    });
});
