import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { sentWith, setUpPrintStation } from '../../support/kitchen.js';
import { fakePrinter, sentToPrinter, asText } from '../../support/webusb.js';

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('33-kitchen-printer', () => {
    test.describe.configure({ timeout: 240_000 });

    test('two orders print one after the other, in the order they arrived', { tag: ['@REQ-004-BR-7'] }, async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        const page = await asRole(cashier);
        await fakePrinter(page);
        await setUpPrintStation(page, uniq('Dapur'));

        const first = arrange('kitchen-sale', { prefix: uniq('Ctk') });
        const second = arrange('kitchen-sale', { prefix: uniq('Ctk') });

        // Both may come in one poll or in two; either way none is lost and the older one is first.
        await expect.poll(() => sentWith(page, first.invoice), { timeout: 15_000 }).toBeDefined();
        await expect.poll(() => sentWith(page, second.invoice), { timeout: 15_000 }).toBeDefined();

        const tickets = (await sentToPrinter(page)).map(asText);
        const at = (invoice) => tickets.findIndex((text) => text.includes(invoice));
        expect(at(first.invoice)).toBeLessThan(at(second.invoice));
        expect(tickets.filter((text) => text.includes(first.invoice))).toHaveLength(1);
        expect(tickets.filter((text) => text.includes(second.invoice))).toHaveLength(1);
    });
});
