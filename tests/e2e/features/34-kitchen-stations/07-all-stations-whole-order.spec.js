import { test, expect } from '../../support/fixtures.js';
import { role } from '../../support/inventory.js';

// REQ-006 AC-06: Semua stasiun shows each order whole, with the station per item and an "x dari y siap" count.
test.describe('34-kitchen-stations: Semua stasiun', () => {
    test('@REQ-006-AC-06 the whole order shows its stations and counts the ready items', async ({ asRole, arrange, uniq }) => {
        const bar = uniq('Bar');
        const sale = arrange('kitchen-sale', { prefix: uniq('Utuh'), flagged: 2, station: bar });
        const [dapurItem, barItem] = sale.flagged;

        const page = await asRole(role('cashier'));
        await page.goto('/dashboard/kitchen');
        const card = page.locator('article', { hasText: sale.invoice });
        const drink = card.getByRole('listitem').filter({ hasText: barItem });
        const food = card.getByRole('listitem').filter({ hasText: dapurItem });

        await expect(card).toContainText('0 dari 2 siap');
        await drink.getByRole('button', { name: 'Mulai buat', exact: true }).click();
        await expect(drink).toContainText('Sedang dibuat');
        await drink.getByRole('button', { name: 'Siap', exact: true }).click();

        await expect(card).toContainText('1 dari 2 siap');
        await expect(drink).toContainText(bar);
        await expect(food).toContainText('Dapur');
        await expect(food).toContainText('Diterima');
    });
});
