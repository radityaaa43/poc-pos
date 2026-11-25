import { test, expect } from '../../support/fixtures.js';

test('the portal refuses a wrong, missing or foreign token', async ({ arrange, page }) => {
    arrange('public.reset-throttle');
    const sale = arrange('public.sale', { method: 'cash' });
    const other = arrange('public.sale', { method: 'cash' });
    const status = async (path) => (await page.goto(path)).status();

    expect(await status(`/portal/transactions/${sale.invoice}?token=not-the-token`)).toBe(404);
    expect(await status(`/portal/transactions/${sale.invoice}`)).toBe(404);
    // A token opens its own invoice only.
    expect(await status(`/portal/transactions/${sale.invoice}?token=${other.token}`)).toBe(404);
    await expect(page.getByText(sale.invoice)).toHaveCount(0);

    expect(await status(`/portal/transactions/${sale.invoice}?token=${sale.token}`)).toBe(200);
});
