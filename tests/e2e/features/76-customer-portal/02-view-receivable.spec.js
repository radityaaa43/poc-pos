import { test, expect } from '../../support/fixtures.js';

test('a pay-later sale shows what is still owed and a pay button', async ({ arrange, page }) => {
    arrange('public.reset-throttle');
    const sale = arrange('public.sale', { method: 'pay_later', qty: 2, paid: 5000 });

    await page.goto(`/portal/transactions/${sale.invoice}?token=${sale.token}`);

    await expect(page.getByText('Belum Lunas', { exact: true })).toBeVisible();
    await expect(page.getByText('Sisa Pembayaran')).toBeVisible();
    await expect(page.getByText(/Jatuh tempo:/)).toBeVisible();
    // 20.000 total, 5.000 already paid.
    await expect(page.getByText(/Rp\s?15\.000/)).toBeVisible();
    await expect(page.getByRole('link', { name: 'Bayar Sekarang' })).toBeVisible();
});

test('a settled receivable hides the pay button', async ({ arrange, page }) => {
    arrange('public.reset-throttle');
    const sale = arrange('public.sale', { method: 'pay_later', qty: 2, paid: 20000 });

    await page.goto(`/portal/transactions/${sale.invoice}?token=${sale.token}`);

    await expect(page.getByText(sale.invoice, { exact: true })).toBeVisible();
    await expect(page.getByText('Sisa Pembayaran')).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Bayar Sekarang' })).toHaveCount(0);
});
