import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { sentWith, setUpPrintStation } from '../../support/kitchen.js';
import { fakePrinter, asText, INIT, CUT } from '../../support/webusb.js';

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('33-kitchen-printer', () => {
    test.describe.configure({ timeout: 240_000 });

    test('a paid dine-in order prints a ticket within 10 seconds, without prices, and the card keeps its status', { tag: ['@REQ-004-AC-01'] }, async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        const page = await asRole(cashier);
        await fakePrinter(page);
        // The station comes first: it prints only what reaches the kitchen after it was named.
        await setUpPrintStation(page, uniq('Dapur'));

        const note = uniq('tanpa-gula');
        const sale = arrange('kitchen-sale', { prefix: uniq('Ctk'), dine: 1, note });

        await expect.poll(() => sentWith(page, sale.table_name), { timeout: 10_000 }).toBeDefined();
        const bytes = await sentWith(page, sale.table_name);
        const ticket = asText(bytes);

        expect(bytes.slice(0, 2)).toEqual(INIT);
        expect(bytes.slice(-4)).toEqual(CUT);
        expect(ticket).toContain(sale.table_name);
        expect(ticket).toMatch(/Dine-in\s+\d{2}:\d{2}\n/);
        expect(ticket).toContain(`1 x ${sale.flagged[0]}`);
        expect(ticket).toContain(`  ${note}`);
        expect(ticket).not.toContain('BELUM LUNAS');
        // No prices: the product sells for 10000.
        expect(ticket).not.toMatch(/Rp|10000|10\.000|10,000/);

        // BR-9: printing never moves the order along; the card still says it was only accepted.
        await expect(page.locator('article', { hasText: sale.table_name })).toContainText('Diterima');
    });
});
