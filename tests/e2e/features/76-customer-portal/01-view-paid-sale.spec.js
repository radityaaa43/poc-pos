import { test, expect } from '../../support/fixtures.js';

test('a guest sees a paid sale on the portal with every price line', async ({ arrange, page }) => {
    arrange('public.reset-throttle');
    // 3 x 10.000 - 2.000 discount + 1.000 tax + 3.000 shipping = 32.000, paid with 37.000 cash.
    const sale = arrange('public.sale', { method: 'cash', qty: 3, discount: 2000, tax: 1000, shipping: 3000 });

    const response = await page.goto(`/portal/transactions/${sale.invoice}?token=${sale.token}`);

    expect(response.status()).toBe(200);
    await expect(page).toHaveTitle(new RegExp(`Invoice ${sale.invoice}`));
    await expect(page.getByText(sale.invoice, { exact: true })).toBeVisible();
    await expect(page.getByText('Lunas', { exact: true })).toBeVisible();
    await expect(page.getByText(`${sale.product} x3`)).toBeVisible();

    const row = (label) => page.getByText(label, { exact: true }).locator('xpath=..');
    await expect(row('Subtotal')).toContainText(/Rp\s?30\.000/);
    await expect(row('Diskon')).toContainText(/-Rp\s?2\.000/);
    await expect(row('PPN')).toContainText(/Rp\s?1\.000/);
    await expect(row('Ongkir')).toContainText(/Rp\s?3\.000/);
    await expect(row('Total')).toContainText(/Rp\s?32\.000/);
    await expect(row('Tunai')).toContainText(/Rp\s?37\.000/);
    await expect(row('Kembali')).toContainText(/Rp\s?5\.000/);

    // Nothing is owed, and no staff chrome is shown to a guest.
    await expect(page.getByText('Sisa Pembayaran')).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Bayar Sekarang' })).toHaveCount(0);
    await expect(page.getByText('Terima kasih telah berbelanja')).toBeVisible();
});
