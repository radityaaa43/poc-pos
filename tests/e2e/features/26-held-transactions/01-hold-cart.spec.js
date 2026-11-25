import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('26-held-transactions', () => {
    // Multi-page flows: the shared 4-CPU machine can take longer than the default 30 s.
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('the cashier holds the cart with a label and the POS is empty again', async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        arrange('posops.clear-carts');
        const { title } = arrange('product', { title: uniq('Roti'), stock: 20 });
        const label = uniq('Meja');
        const page = await asRole(cashier);

        await page.goto('/dashboard/transactions');
        await page.getByPlaceholder(/Cari produk/).fill(title);
        await page.getByRole('button', { name: title }).click();
        await expect(page.getByText('1 item', { exact: true })).toBeVisible();

        // The hold button only shows once the cart has items.
        await page.getByRole('button', { name: 'Tahan', exact: true }).click();
        await page.getByPlaceholder('Label (opsional)').fill(label);
        await page.getByRole('button', { name: 'OK', exact: true }).click();

        await expect(page.getByText('Keranjang kosong', { exact: true })).toBeVisible();
        await page.getByRole('button', { name: /Transaksi Ditahan/ }).click();
        await expect(page.getByText(label, { exact: true })).toBeVisible();
        await expect(page.getByText(/1 item • Rp\s*10\.000/)).toBeVisible();
    });
});
