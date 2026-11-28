import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { setUpPrintDevice } from '../../support/kitchen.js';
import { fakePrinter, sentToPrinter, asText } from '../../support/webusb.js';

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('33-kitchen-printer', () => {
    test.describe.configure({ timeout: 240_000 });

    // #410 D1: another printer the browser was granted (a receipt printer) must never receive kitchen tickets.
    test('with the kitchen printer disconnected nothing prints on another granted printer', async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        const page = await asRole(cashier);
        await fakePrinter(page, { granted: true });
        const panel = await setUpPrintDevice(page, uniq('Dapur'));
        await panel.getByRole('button', { name: 'Putuskan' }).click();
        await expect(page.getByRole('alert').filter({ hasText: 'Printer dapur tidak terhubung' })).toBeVisible();

        const sale = arrange('kitchen-sale', { prefix: uniq('Ctk') });
        const card = page.locator('article', { hasText: sale.invoice });
        await expect(card.getByText('Belum tercetak')).toBeVisible({ timeout: 20_000 });
        expect((await sentToPrinter(page)).map(asText).filter((text) => text.includes(sale.invoice))).toEqual([]);
    });
});
