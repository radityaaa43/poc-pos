import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// The print page is for the cashier who made the sale (scenario: cashier@e2e.test).
const cashier = roles.find((role) => role.key === 'cashier');

async function copyShareLink(asRole, invoice) {
    const page = await asRole(cashier);
    await page.context().grantPermissions(['clipboard-read', 'clipboard-write']);
    page.on('dialog', (dialog) => dialog.accept());

    await page.goto(`/dashboard/transactions/${invoice}/print`);
    await page.getByRole('button', { name: 'Share' }).click();

    return page.evaluate(() => navigator.clipboard.readText());
}

test('the Share button copies the portal link of the invoice', async ({ arrange, asRole }) => {
    const sale = arrange('public.sale', { method: 'pay_later', outlet: 'e2e' });

    const link = await copyShareLink(asRole, sale.invoice);

    expect(link).toContain(`/portal/transactions/${sale.invoice}?token=${sale.token}`);
});

// Bug #111: the copied link repeats the origin (http://host/http://host/portal/...), so it does not open.
test.fixme('a guest opens the link the cashier copied', async ({ arrange, asRole, page: guest }) => {
    arrange('public.reset-throttle');
    const sale = arrange('public.sale', { method: 'pay_later', outlet: 'e2e' });

    await guest.goto(await copyShareLink(asRole, sale.invoice));

    await expect(guest.getByText(sale.invoice, { exact: true })).toBeVisible();
    await expect(guest.getByText('Sisa Pembayaran')).toBeVisible();
});
