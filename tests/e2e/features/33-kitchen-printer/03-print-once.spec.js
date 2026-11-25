import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { sentWith, setUpPrintDevice } from '../../support/kitchen.js';
import { fakePrinter, sentToPrinter, asText } from '../../support/webusb.js';

const cashier = roles.find((role) => role.key === 'cashier');

const isTicketsPoll = (response) => response.request().method() === 'GET' && /\/kitchen\/devices\/\d+\/tickets$/.test(response.url());
const isPrintRecord = (response) => response.request().method() === 'POST' && /\/kitchen\/devices\/\d+\/prints$/.test(response.url());
const printsOf = async (page, invoice) => (await sentToPrinter(page)).map(asText).filter((text) => text.includes(invoice));

test.describe('33-kitchen-printer', () => {
    test.describe.configure({ timeout: 240_000 });

    test('a ticket prints once per print device, not again after a reload', { tag: ['@REQ-004-AC-05'] }, async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        // Two devices, each with its own print device name, both named before the sale.
        const a = await asRole(cashier);
        await fakePrinter(a);
        await setUpPrintDevice(a, uniq('DapurA'));
        const b = await asRole(cashier);
        await fakePrinter(b);
        await setUpPrintDevice(b, uniq('DapurB'));

        const recorded = a.waitForResponse(isPrintRecord);
        const sale = arrange('kitchen-sale', { prefix: uniq('Ctk') });
        await expect.poll(() => sentWith(a, sale.invoice), { timeout: 10_000 }).toBeDefined();
        await expect.poll(() => sentWith(b, sale.invoice), { timeout: 10_000 }).toBeDefined();
        await recorded; // the server knows device A printed it before the page goes away

        // Reload device A with its printer already granted, and let it poll twice (on load, then 5 s later).
        await fakePrinter(a, { granted: true });
        const firstPoll = a.waitForResponse(isTicketsPoll);
        await a.reload();
        expect((await (await firstPoll).json()).tickets).toEqual([]);
        const secondPoll = a.waitForResponse(isTicketsPoll, { timeout: 15_000 });
        expect((await (await secondPoll).json()).tickets).toEqual([]);
        expect(await sentToPrinter(a)).toEqual([]);

        // Device B printed the same sale once on its own and did not print it again either.
        expect(await printsOf(b, sale.invoice)).toHaveLength(1);

        // BR-9: neither print moved the order along.
        const items = arrange('kitchen.items', { invoice: sale.invoice });
        expect(items.statuses).toEqual(['accepted']);
    });
});
