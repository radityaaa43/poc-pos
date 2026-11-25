import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// History lists a cashier's own sales; the scenario sells as cashier@e2e.test.
const cashier = roles.find((role) => role.key === 'cashier');

// Bug #112: the WhatsApp text links to /share/transactions/<invoice> without ?token=, so a customer gets a 404.
test.fixme('the WhatsApp share link on the history page carries a working public invoice link', async ({ arrange, asRole, request }) => {
    arrange('public.reset-throttle');
    const sale = arrange('public.sale', { method: 'cash', outlet: 'e2e' });
    const page = await asRole(cashier);

    await page.goto('/dashboard/transactions/history');
    await page.getByRole('button', { name: 'Filter' }).click();
    await page.getByPlaceholder('TRX-...').fill(sale.invoice);
    await page.keyboard.press('Enter');
    const share = page.getByRole('link', { name: 'Bagikan ke WhatsApp' }).first();
    await expect(share).toBeVisible();

    const text = new URL(await share.getAttribute('href')).searchParams.get('text');
    expect(text).toContain(`Invoice ${sale.invoice}`);
    const link = new URL(text.match(/https?:\/\/\S+/)[0]);
    expect(link.searchParams.get('token')).toBe(sale.token);

    // The shared link opens for a guest.
    expect((await request.get(link.pathname + link.search)).status()).toBe(200);
});

test('the history page offers a WhatsApp share link for the invoice', async ({ arrange, asRole }) => {
    const sale = arrange('public.sale', { method: 'cash', outlet: 'e2e' });
    const page = await asRole(cashier);

    await page.goto('/dashboard/transactions/history');
    await page.getByRole('button', { name: 'Filter' }).click();
    await page.getByPlaceholder('TRX-...').fill(sale.invoice);
    await page.keyboard.press('Enter');

    const share = page.getByRole('link', { name: 'Bagikan ke WhatsApp' }).first();
    await expect(share).toBeVisible();
    expect(new URL(await share.getAttribute('href')).searchParams.get('text')).toContain(`Invoice ${sale.invoice}`);
});
