import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { fakePrinter, asText } from '../../support/webusb.js';
import { sentWith, setUpPrintDevice } from '../../support/kitchen.js';

const manager = roles.find((role) => role.key === 'manager');
const cashier = roles.find((role) => role.key === 'cashier');

// REQ-008 AC-18: the normal kitchen ticket lists each item's options (free ones included) and its note, no prices.
// The DIBATALKAN, CETAK ULANG and TAMBAHAN tickets read the same presenter (KitchenOrders), proven by the PHPUnit
// and Vitest coverage for the shared read model and escpos; a real printer is a COVERAGE.md manual note.
test.describe('49-dine-modifiers', () => {
    test.describe.configure({ timeout: 240_000 });

    test('a kitchen ticket prints the chosen options and the note per item, without prices', { tag: ['@REQ-008-AC-18'] }, async ({ asRole, arrange, uniq }) => {
        const title = uniq('Soto');
        const { product_id, options } = arrange('modifier-menu', { title, needs_prep: 1 });
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const note = uniq('tanpa sambal');

        // A pay-later guest order carrying the chosen options (the paid Large plus the free Gula Less) and note;
        // accepting sends it to the kitchen unpaid.
        arrange('dine.order', { token, product_id, qty: 1, option: 'pay_later', modifier_option_ids: `${options.Large},${options.Less}`, note });

        // The print device prints only what reaches the kitchen after it was named.
        arrange('open-shift');
        const printerPage = await asRole(cashier);
        await fakePrinter(printerPage);
        await setUpPrintDevice(printerPage, uniq('Dapur'));

        arrange('open-shift', { cashier: manager.email, cash: 100000 });
        const managerPage = await asRole(manager);
        await managerPage.goto('/dashboard/dine-orders');
        const card = managerPage.locator('div.rounded-2xl', { hasText: table_name });
        managerPage.once('dialog', (confirm) => confirm.accept());
        await card.getByRole('button', { name: 'Terima' }).click();
        await expect(card.getByRole('button', { name: 'Terima' })).toHaveCount(0);

        await expect.poll(() => sentWith(printerPage, table_name), { timeout: 20_000 }).toBeDefined();
        const ticket = asText(await sentWith(printerPage, table_name));

        expect(ticket).toContain(`1 x ${title}`);
        expect(ticket).toContain('+ Large');
        expect(ticket).toContain('+ Less');
        expect(ticket).toContain(`  ${note}`);
        // The menu sells for 20.000 and Large adds 5.000: a kitchen ticket never prints a price.
        expect(ticket).not.toMatch(/Rp|20\.000|25\.000|20000|25000/);
    });
});
