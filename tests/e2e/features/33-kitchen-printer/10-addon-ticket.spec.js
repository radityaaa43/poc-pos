import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { sentWith, setUpPrintDevice } from '../../support/kitchen.js';
import { addToCart, cashInput, finish } from '../../support/pos.js';
import { fakePrinter, sentToPrinter, asText } from '../../support/webusb.js';

const cashier = roles.find((role) => role.key === 'cashier');

const isTicketsPoll = (response) => response.request().method() === 'GET' && /\/kitchen\/print-devices\/\d+\/tickets$/.test(response.url());
const isPrintRecord = (response) => response.request().method() === 'POST' && /\/kitchen\/print-devices\/\d+\/prints$/.test(response.url());
// The add-on tickets of one order that reached the fake printer, as text: marked TAMBAHAN, numbered after the original.
const addOnTickets = async (page, invoice) => (await sentToPrinter(page)).map(asText).filter((text) => text.includes(`${invoice}\nTAMBAHAN\n`));
const addOnCount = (page, invoice) => async () => (await addOnTickets(page, invoice)).length;

// A print device on the kitchen display of this page's own browser, named before any sale of the test (its cut-off).
async function printDevice({ asRole, uniq }, name) {
    const page = await asRole(cashier);
    await fakePrinter(page);
    await setUpPrintDevice(page, uniq(name));

    return page;
}

test.describe('33-kitchen-printer', () => {
    test.describe.configure({ timeout: 240_000 });

    test('Tambah pesanan prints a TAMBAHAN ticket with the original order number and only the added item', { tag: ['@REQ-004-AC-03'] }, async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        const kitchen = await printDevice({ asRole, uniq }, 'Dapur');
        const original = arrange('kitchen-sale', { prefix: uniq('Asal') });
        await expect.poll(() => sentWith(kitchen, original.invoice), { timeout: 10_000 }).toBeDefined();
        expect(asText(await sentWith(kitchen, original.invoice))).not.toContain('TAMBAHAN');

        const title = uniq('Susulan');
        arrange('kitchen.flag', { product_id: arrange('product', { title, stock: 10 }).product_id });
        const till = await asRole(cashier);
        await till.goto(`/dashboard/transactions/${original.invoice}/print`);
        await till.getByRole('button', { name: 'Tambah pesanan' }).click();
        await expect(till.getByText(`Pesanan tambahan untuk ${original.invoice}`)).toBeVisible({ timeout: 45_000 });
        await till.waitForLoadState('networkidle');
        await addToCart(till, title);
        await cashInput(till).fill('50000');
        const addOn = await finish(till);

        await expect.poll(addOnCount(kitchen, original.invoice), { timeout: 10_000 }).toBe(1);
        const [ticket] = await addOnTickets(kitchen, original.invoice);
        expect(ticket).toContain(`1 x ${title}`);
        expect(ticket).not.toContain(original.flagged[0]); // only the added item
        expect(ticket).not.toContain(addOn); // the kitchen knows the order by the original's number
        expect(ticket).not.toContain('BELUM LUNAS');
        expect(ticket).not.toContain('Rp'); // no price on a ticket
    });

    test('a TAMBAHAN ticket prints once per print device, not again after a reload', { tag: ['@REQ-004-AC-03'] }, async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        const a = await printDevice({ asRole, uniq }, 'DapurA');
        const b = await printDevice({ asRole, uniq }, 'DapurB');
        const originalRecorded = a.waitForResponse(isPrintRecord);
        const original = arrange('kitchen-sale', { prefix: uniq('Asal') });
        await expect.poll(() => sentWith(a, original.invoice), { timeout: 10_000 }).toBeDefined();
        await originalRecorded;

        const addOnRecorded = a.waitForResponse(isPrintRecord);
        arrange('kitchen-sale', { prefix: uniq('Susulan'), add_on_to: original.invoice });
        await expect.poll(addOnCount(a, original.invoice), { timeout: 10_000 }).toBe(1);
        await expect.poll(addOnCount(b, original.invoice), { timeout: 10_000 }).toBe(1);
        await addOnRecorded; // the server knows device A printed it before the page goes away

        // Reload device A with its printer already granted, and let it poll twice (on load, then 5 s later).
        await fakePrinter(a, { granted: true });
        await a.goto('about:blank'); // the old page stops polling, so the next poll seen is the new page's
        const firstPoll = a.waitForResponse(isTicketsPoll);
        await a.goto('/dashboard/kitchen');
        expect((await (await firstPoll).json()).tickets).toEqual([]);
        const secondPoll = a.waitForResponse(isTicketsPoll, { timeout: 15_000 });
        expect((await (await secondPoll).json()).tickets).toEqual([]);
        expect(await sentToPrinter(a)).toEqual([]);

        // Device B printed the add-on once on its own and did not print it again either.
        expect(await addOnTickets(b, original.invoice)).toHaveLength(1);
    });

    test('a pay-later add-on prints TAMBAHAN with BELUM LUNAS', { tag: ['@REQ-004-AC-03'] }, async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        const page = await printDevice({ asRole, uniq }, 'Dapur');
        const original = arrange('kitchen-sale', { prefix: uniq('Asal') });
        const added = arrange('kitchen-sale', { prefix: uniq('Susulan'), add_on_to: original.invoice, pay_later: 1 });

        await expect.poll(addOnCount(page, original.invoice), { timeout: 10_000 }).toBe(1);
        const [ticket] = await addOnTickets(page, original.invoice);
        expect(ticket).toContain('BELUM LUNAS');
        expect(ticket).toContain(`1 x ${added.flagged[0]}`);
    });
});
