import { test, expect as baseExpect } from '../../support/fixtures.js';
import { openMenuCart } from '../../support/dine-bill.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

// REQ-005 AC-01: the guest of an accepted, unpaid order at an outlet with a gateway sees the total and Bayar sekarang.
test.describe('80-dine-online-payment', () => {
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('an accepted unpaid order at a gateway outlet shows the total and Bayar sekarang', { tag: ['@REQ-005-AC-01'] }, async ({ arrange, page }) => {
        const bill = arrange('dine.bill', { gateway: 'midtrans' });
        await page.goto(`/dine-order/${bill.access_token}`);

        const panel = page.getByTestId('guest-pay');
        await expect(panel).toContainText(/Rp\s*20\.000/);
        await expect(panel.getByRole('button', { name: 'Bayar sekarang' })).toBeEnabled();
        await expect(panel.getByRole('button', { name: 'Bayar dengan metode lain' })).toBeEnabled();
        // The panel replaces the walk to the counter, and the page names no invoice.
        await expect(page.getByText(/Silakan menuju kasir/)).toHaveCount(0);
        await expect(page.locator('body')).not.toContainText(bill.invoice);
    });

    test('there is no pay button before staff accept the order', { tag: ['@REQ-005-AC-01'] }, async ({ arrange, page }) => {
        const bill = arrange('dine.bill', { gateway: 'midtrans', accepted: '0' });
        await page.goto(`/dine-order/${bill.access_token}`);

        await expect(page.getByTestId('dine-status')).toHaveText('Menunggu Konfirmasi');
        await expect(page.getByTestId('guest-pay')).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Bayar sekarang' })).toHaveCount(0);
    });

    test('the menu of a gateway outlet says Pesan, with the pay-online hint', { tag: ['@REQ-005-AC-01'] }, async ({ arrange, page }) => {
        const bill = arrange('dine.bill', { gateway: 'midtrans' });

        const cart = await openMenuCart(page, bill.table_token);

        await expect(cart.getByRole('button', { name: 'Pesan', exact: true })).toBeVisible();
        await expect(cart.getByText('Bisa bayar online setelah pesanan diterima')).toBeVisible();
        await expect(cart.getByRole('button', { name: 'Pesan — Bayar di Kasir' })).toHaveCount(0);
    });
});
