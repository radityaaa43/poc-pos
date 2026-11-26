import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { sentWith, setUpPrintDevice } from '../../support/kitchen.js';
import { fakePrinter, sentToPrinter, asText } from '../../support/webusb.js';

const cashier = roles.find((role) => role.key === 'cashier');

const isTicketsPoll = (response) => response.request().method() === 'GET' && /\/kitchen\/print-devices\/\d+\/tickets$/.test(response.url());
const isPrintRecord = (response) => response.request().method() === 'POST' && /\/kitchen\/print-devices\/\d+\/prints$/.test(response.url());
const isCancelRecord = (response) => response.request().method() === 'POST' && /\/kitchen\/print-devices\/\d+\/cancellation-prints$/.test(response.url());

// The DIBATALKAN tickets of one sale that reached the fake printer, as text (the number on a POS sale is its invoice).
const cancelTickets = async (page, invoice) => (await sentToPrinter(page)).map(asText).filter((text) => text.includes('DIBATALKAN') && text.includes(invoice));
const cancelCount = (page, invoice) => async () => (await cancelTickets(page, invoice)).length;

// Waits for two ticket polls that started after this call (the poll interval is 5 s): a negative check needs a whole cycle.
const waitOutPolls = async (page) => {
    await page.waitForResponse(isTicketsPoll, { timeout: 15_000 });

    return page.waitForResponse(isTicketsPoll, { timeout: 15_000 });
};

const switchAutoPrint = async (panel, on) => {
    await panel.getByRole('switch', { name: 'Cetak otomatis' }).click();
    await expect(panel.getByRole('switch', { name: 'Cetak otomatis' })).toHaveAttribute('aria-checked', String(on));
};

// Waits until `page` printed the original ticket of the sale and the server recorded it (`recorded` was started before the sale).
const originalPrinted = async (page, invoice, recorded) => {
    await expect.poll(() => sentWith(page, invoice), { timeout: 10_000 }).toBeDefined();
    await recorded;
};

// Names a print device and sells one flagged order after it, so the device prints that order's original ticket.
async function printedSale({ asRole, arrange, uniq }) {
    arrange('open-shift', { cash: 100000 });
    const page = await asRole(cashier);
    await fakePrinter(page);
    await setUpPrintDevice(page, uniq('Dapur'));
    const recorded = page.waitForResponse(isPrintRecord);
    const sale = arrange('kitchen-sale', { prefix: uniq('Ctk') });
    await originalPrinted(page, sale.invoice, recorded);

    return { page, sale };
}

test.describe('33-kitchen-printer', () => {
    test.describe.configure({ timeout: 240_000 });

    test('a full return prints a DIBATALKAN ticket with the order, the menu and the whole quantity', { tag: ['@REQ-004-AC-04'] }, async ({ asRole, arrange, uniq }) => {
        const { page, sale } = await printedSale({ asRole, arrange, uniq });

        const recorded = page.waitForResponse(isCancelRecord);
        const result = arrange('kitchen.return', { invoice: sale.invoice });
        expect(result).toMatchObject({ cancelled_qty: 1, item_status: 'cancelled' });

        await expect.poll(cancelCount(page, sale.invoice), { timeout: 12_000 }).toBe(1);
        const [ticket] = await cancelTickets(page, sale.invoice);
        expect(ticket).toContain('DIBATALKAN');
        expect(ticket).toContain(sale.invoice);
        expect(ticket).toContain(`1 x ${sale.flagged[0]}`);
        expect(ticket).not.toContain('Rp'); // no price on a ticket
        await recorded; // the server knows this device printed it
    });

    test('a partial return prints the returned quantity, and a second one prints a second ticket without the first again', { tag: ['@REQ-004-AC-04'] }, async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        const page = await asRole(cashier);
        await fakePrinter(page);
        const panel = await setUpPrintDevice(page, uniq('Dapur'));
        // The sale is a single unit: pause printing (the device is already named), make the line 3 units, then print its original.
        await switchAutoPrint(panel, false);
        const sale = arrange('kitchen-sale', { prefix: uniq('Ctk') });
        arrange('kitchen.set-item', { invoice: sale.invoice, status: 'accepted', qty: 3 });
        const originalRecorded = page.waitForResponse(isPrintRecord);
        await switchAutoPrint(panel, true);
        await originalPrinted(page, sale.invoice, originalRecorded);
        expect(asText(await sentWith(page, sale.invoice))).toContain(`3 x ${sale.flagged[0]}`);

        const firstRecorded = page.waitForResponse(isCancelRecord);
        expect(arrange('kitchen.return', { invoice: sale.invoice, qty: 1 })).toMatchObject({ cancelled_qty: 1, item_status: 'accepted' });
        await expect.poll(cancelCount(page, sale.invoice), { timeout: 12_000 }).toBe(1);
        expect((await cancelTickets(page, sale.invoice))[0]).toContain(`1 x ${sale.flagged[0]}`);
        await firstRecorded;

        const secondRecorded = page.waitForResponse(isCancelRecord);
        expect(arrange('kitchen.return', { invoice: sale.invoice, qty: 1 })).toMatchObject({ cancelled_qty: 1, item_status: 'accepted' });
        await expect.poll(cancelCount(page, sale.invoice), { timeout: 12_000 }).toBe(2);
        await secondRecorded;

        // Another whole poll cycle later the first ticket was not printed again.
        await waitOutPolls(page);
        expect(await cancelTickets(page, sale.invoice)).toHaveLength(2);
    });

    test('a draft return prints nothing, a completed one prints once', { tag: ['@REQ-004-AC-04'] }, async ({ asRole, arrange, uniq }) => {
        const { page, sale } = await printedSale({ asRole, arrange, uniq });

        expect(arrange('kitchen.return-draft', { invoice: sale.invoice })).toHaveProperty('sales_return_id');
        await waitOutPolls(page); // a negative check: longer than one 5 s ticket poll
        expect(await cancelTickets(page, sale.invoice)).toEqual([]);

        // The draft stays a draft; a completed return of the same line is the one the kitchen is told about.
        expect(arrange('kitchen.return', { invoice: sale.invoice })).toMatchObject({ cancelled_qty: 1, item_status: 'cancelled' });
        await expect.poll(cancelCount(page, sale.invoice), { timeout: 12_000 }).toBe(1);
        await waitOutPolls(page);
        expect(await cancelTickets(page, sale.invoice)).toHaveLength(1);
    });

    test('after a reload the DIBATALKAN ticket is not printed again', { tag: ['@REQ-004-AC-04'] }, async ({ asRole, arrange, uniq }) => {
        const { page, sale } = await printedSale({ asRole, arrange, uniq });

        const recorded = page.waitForResponse(isCancelRecord);
        arrange('kitchen.return', { invoice: sale.invoice });
        await expect.poll(cancelCount(page, sale.invoice), { timeout: 12_000 }).toBe(1);
        await recorded; // the server knows this device printed it before the page goes away

        // Reload with the printer already granted, and let it poll twice (on load, then 5 s later).
        await fakePrinter(page, { granted: true });
        const firstPoll = page.waitForResponse(isTicketsPoll);
        await page.reload();
        const body = await (await firstPoll).json();
        expect(body.tickets).toEqual([]);
        expect(body.cancellations).toEqual([]);
        await page.waitForResponse(isTicketsPoll, { timeout: 15_000 });
        expect(await sentToPrinter(page)).toEqual([]);
    });

    test('a print device that never printed the original prints no DIBATALKAN ticket', { tag: ['@REQ-004-AC-04'] }, async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        // Device B is named before the sale but paused, so it never prints the original; device A prints it.
        const b = await asRole(cashier);
        await fakePrinter(b);
        const panelB = await setUpPrintDevice(b, uniq('DapurB'));
        await switchAutoPrint(panelB, false);
        const a = await asRole(cashier);
        await fakePrinter(a);
        await setUpPrintDevice(a, uniq('DapurA'));

        const recorded = a.waitForResponse(isPrintRecord);
        const sale = arrange('kitchen-sale', { prefix: uniq('Ctk') });
        await originalPrinted(a, sale.invoice, recorded);
        const cancelRecorded = a.waitForResponse(isCancelRecord);
        arrange('kitchen.return', { invoice: sale.invoice });
        await expect.poll(cancelCount(a, sale.invoice), { timeout: 12_000 }).toBe(1);
        await cancelRecorded;

        // Device B starts printing only now: the order is cancelled and its original was never printed there.
        const polled = b.waitForResponse(isTicketsPoll, { timeout: 15_000 });
        await switchAutoPrint(panelB, true);
        const body = await (await polled).json();
        expect(body.cancellations).toEqual([]);
        await waitOutPolls(b);
        expect(await sentToPrinter(b)).toEqual([]);
    });
});
