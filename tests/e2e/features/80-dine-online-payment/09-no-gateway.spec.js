import { test, expect as baseExpect } from '../../support/fixtures.js';
import { openMenuCart } from '../../support/dine-bill.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

// REQ-005 AC-07: an outlet without Midtrans or Xendit offers no online payment, on the status page or the menu.
test.describe('80-dine-online-payment', () => {
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('an accepted order at an outlet without a gateway has no pay button, only Silakan bayar di kasir', { tag: ['@REQ-005-AC-07'] }, async ({ arrange, page }) => {
        const bill = arrange('dine.bill', { gateway: 'none' });
        await page.goto(`/dine-order/${bill.access_token}`);

        const panel = page.getByTestId('guest-pay');
        await expect(panel.getByRole('heading', { name: 'Silakan bayar di kasir' })).toBeVisible();
        await expect(page.getByRole('button', { name: /Bayar/ })).toHaveCount(0);
        await expect(panel.getByRole('img', { name: 'QRIS' })).toHaveCount(0);
        await expect(page.getByText(/Silakan menuju kasir untuk membayar/)).toBeVisible();
    });

    test('the menu of an outlet without a gateway keeps Pesan — Bayar di Kasir', { tag: ['@REQ-005-AC-07'] }, async ({ arrange, page }) => {
        const bill = arrange('dine.bill', { gateway: 'none' });

        const cart = await openMenuCart(page, bill.table_token);

        await expect(cart.getByRole('button', { name: 'Pesan — Bayar di Kasir' })).toBeVisible();
        await expect(cart.getByText('Bisa bayar online setelah pesanan diterima')).toHaveCount(0);
    });
});
