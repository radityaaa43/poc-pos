import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('26-held-transactions', () => {
    // Multi-page flows: the shared 4-CPU machine can take longer than the default 30 s.
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('resuming a held transaction puts its items back in the cart', async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        const { label } = arrange('posops.held-cart', { label: uniq('Meja'), qty: 2 });
        const page = await asRole(cashier);

        await page.goto('/dashboard/transactions');
        await expect(page.getByText('Keranjang kosong', { exact: true })).toBeVisible();
        await page.getByRole('button', { name: /Transaksi Ditahan/ }).click();
        await expect(page.getByText(label, { exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Lanjutkan' }).click();

        await expect(page.getByText('2 item', { exact: true })).toBeVisible();
        await expect(page.getByText('Keranjang kosong', { exact: true })).toHaveCount(0);
        await expect(page.getByRole('button', { name: /Transaksi Ditahan/ })).toHaveCount(0);
    });

    test('a held transaction cannot be resumed while the cart has items', async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        arrange('posops.held-cart', { label: uniq('Meja') });
        const { title } = arrange('product', { title: uniq('Kopi'), stock: 20 });
        const page = await asRole(cashier);

        await page.goto('/dashboard/transactions');
        await page.getByPlaceholder(/Cari produk/).fill(title);
        await page.getByRole('button', { name: title }).click();
        await expect(page.getByText('1 item', { exact: true })).toBeVisible();

        await page.getByRole('button', { name: /Transaksi Ditahan/ }).click();
        await expect(page.getByRole('button', { name: 'Kosongkan keranjang dulu' })).toBeDisabled();
    });
});
