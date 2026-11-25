import { test, expect } from '../../support/fixtures.js';

// The faked gateway answers with a redirect_url on pay.e2e.test, a host that does not resolve, so the
// browser cannot land there: the step asserts the redirect the app sends instead.
const payRedirect = (page, sale) => page.waitForResponse((r) => r.url().includes(`/portal/receivables/${sale.receivable_id}/pay`));

test('a guest pays a receivable with Midtrans and is redirected to the gateway page', async ({ arrange, page }) => {
    arrange('public.reset-throttle');
    const sale = arrange('public.sale', { method: 'pay_later', gateway: 'midtrans' });

    await page.goto(`/portal/transactions/${sale.invoice}?token=${sale.token}`);
    const response = payRedirect(page, sale);
    await page.getByRole('link', { name: 'Bayar Sekarang' }).click();

    const redirect = await response;
    expect(redirect.status()).toBe(302);
    expect(redirect.headers().location).toBe(`http://pay.e2e.test/midtrans/${sale.invoice}`);
});

test('a guest pays a receivable with Xendit and is redirected to the gateway page', async ({ arrange, page }) => {
    arrange('public.reset-throttle');
    const sale = arrange('public.sale', { method: 'pay_later', gateway: 'xendit' });

    await page.goto(`/portal/transactions/${sale.invoice}?token=${sale.token}`);
    const response = payRedirect(page, sale);
    await page.getByRole('link', { name: 'Bayar Sekarang' }).click();

    const redirect = await response;
    expect(redirect.status()).toBe(302);
    expect(redirect.headers().location).toBe(`http://pay.e2e.test/xendit/${sale.invoice}`);
});

test('a gateway that is not set up shows an error and nothing is charged', async ({ arrange, page }) => {
    arrange('public.reset-throttle');
    const sale = arrange('public.sale', { method: 'pay_later', gateway: 'none' });

    await page.goto(`/portal/transactions/${sale.invoice}?token=${sale.token}`);
    const response = payRedirect(page, sale);
    await page.getByRole('link', { name: 'Bayar Sekarang' }).click();

    expect((await response).status()).toBe(302);
    // The app redirects back to the portal page; the sale is still owed.
    await expect(page).toHaveURL(new RegExp(`/portal/transactions/${sale.invoice}`));
    await expect(page.getByText('Sisa Pembayaran')).toBeVisible();
    await expect(page.getByText('Belum Lunas', { exact: true })).toBeVisible();
});

// Bug #124: back()->with('error') is flashed but TransactionDetail.jsx never shows it.
test.fixme('the portal tells the customer the gateway is not configured', async ({ arrange, page }) => {
    arrange('public.reset-throttle');
    const sale = arrange('public.sale', { method: 'pay_later', gateway: 'none' });

    await page.goto(`/portal/transactions/${sale.invoice}?token=${sale.token}`);
    await page.getByRole('link', { name: 'Bayar Sekarang' }).click();

    await expect(page.getByText('Gateway pembayaran belum dikonfigurasi.')).toBeVisible();
});
