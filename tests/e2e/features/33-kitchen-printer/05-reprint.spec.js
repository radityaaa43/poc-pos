import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { sentWith, setUpPrintDevice } from '../../support/kitchen.js';
import { fakePrinter, sentToPrinter, asText } from '../../support/webusb.js';

const cashier = roles.find((role) => role.key === 'cashier');

const isTicketsPoll = (response) => response.request().method() === 'GET' && /\/kitchen\/print-devices\/\d+\/tickets$/.test(response.url());
const isPrintRecord = (response) => response.request().method() === 'POST' && /\/kitchen\/print-devices\/\d+\/prints$/.test(response.url());
const printsOf = async (page, invoice) => (await sentToPrinter(page)).map(asText).filter((text) => text.includes(invoice));
const reprintsOf = async (page, invoice) => (await printsOf(page, invoice)).filter((text) => text.includes('CETAK ULANG'));
const cardOf = (page, invoice) => page.locator('article', { hasText: invoice });
const reprintButton = (card) => card.getByRole('button', { name: 'Cetak ulang' });

// Counts every print record the page posts from now on: a reprint must post none.
function countRecords(page) {
    const posted = { count: 0 };
    page.on('request', (request) => {
        if (request.method() === 'POST' && /\/kitchen\/print-devices\/\d+\/(cancellation-)?prints$/.test(request.url())) posted.count += 1;
    });

    return posted;
}

test.describe('33-kitchen-printer', () => {
    test.describe.configure({ timeout: 240_000 });

    test('Cetak ulang prints a CETAK ULANG ticket any number of times and records nothing', { tag: ['@REQ-004-AC-06'] }, async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        // An order from before the print device was named: this device never prints it.
        const earlier = arrange('kitchen-sale', { prefix: uniq('Lama') });
        const page = await asRole(cashier);
        await fakePrinter(page);
        await setUpPrintDevice(page, uniq('Dapur'));

        const recorded = page.waitForResponse(isPrintRecord);
        const sale = arrange('kitchen-sale', { prefix: uniq('Ctk') });
        await expect.poll(() => sentWith(page, sale.invoice), { timeout: 10_000 }).toBeDefined();
        await recorded;
        expect(await reprintsOf(page, sale.invoice)).toHaveLength(0);

        // From here on the server must hear nothing: a reprint is not a print record.
        const posted = countRecords(page);
        const card = cardOf(page, sale.invoice);
        await expect(card.getByText('Belum tercetak')).toHaveCount(0);
        for (const count of [1, 2]) {
            await reprintButton(card).click();
            await expect.poll(async () => (await reprintsOf(page, sale.invoice)).length).toBe(count);
        }

        // Only an order this device printed offers it.
        await expect(cardOf(page, earlier.invoice)).toBeVisible();
        await expect(reprintButton(cardOf(page, earlier.invoice))).toHaveCount(0);

        // The original and two reprints; the reprints carry the marker, the same order and no price.
        const tickets = await printsOf(page, sale.invoice);
        expect(tickets).toHaveLength(3);
        expect(tickets[0]).not.toContain('CETAK ULANG');
        for (const ticket of tickets.slice(1)) {
            expect(ticket).toContain('CETAK ULANG');
            expect(ticket).toContain(`1 x ${sale.flagged[0]}`);
            expect(ticket).not.toContain('Rp');
        }

        // A further poll offers nothing new, nothing was posted or left unconfirmed, and the order did not move.
        const poll = page.waitForResponse(isTicketsPoll, { timeout: 15_000 });
        expect((await (await poll).json()).tickets).toEqual([]);
        expect(posted.count).toBe(0);
        expect(await page.evaluate(() => JSON.parse(localStorage.getItem('kitchen.unconfirmed') ?? '[]'))).toEqual([]);
        expect(arrange('kitchen.items', { invoice: sale.invoice }).statuses).toEqual(['accepted']);

        // A reload prints nothing, the reprints included, and the printed order still offers Cetak ulang.
        await fakePrinter(page, { granted: true });
        const firstPoll = page.waitForResponse(isTicketsPoll);
        await page.reload();
        expect((await (await firstPoll).json()).tickets).toEqual([]);
        const secondPoll = page.waitForResponse(isTicketsPoll, { timeout: 15_000 });
        expect((await (await secondPoll).json()).tickets).toEqual([]);
        expect(await sentToPrinter(page)).toEqual([]);
        await expect(reprintButton(cardOf(page, sale.invoice))).toBeVisible();
        expect(posted.count).toBe(0);
    });

    test('with Cetak otomatis off, a printed order still reprints and an unprinted one offers nothing', { tag: ['@REQ-004-AC-06'] }, async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        const page = await asRole(cashier);
        await fakePrinter(page);
        const panel = await setUpPrintDevice(page, uniq('Dapur'));

        const recorded = page.waitForResponse(isPrintRecord);
        const printed = arrange('kitchen-sale', { prefix: uniq('Ctk') });
        await expect.poll(() => sentWith(page, printed.invoice), { timeout: 10_000 }).toBeDefined();
        await recorded;
        await panel.getByRole('switch', { name: 'Cetak otomatis' }).click();
        await expect(panel.getByRole('switch', { name: 'Cetak otomatis' })).toHaveAttribute('aria-checked', 'false');

        const posted = countRecords(page);
        const unprinted = arrange('kitchen-sale', { prefix: uniq('Ctk') });
        await expect(cardOf(page, unprinted.invoice)).toBeVisible();
        await expect(cardOf(page, unprinted.invoice).getByText('Belum tercetak')).toHaveCount(0);
        await expect(reprintButton(cardOf(page, unprinted.invoice))).toHaveCount(0);

        await reprintButton(cardOf(page, printed.invoice)).click();
        await expect.poll(async () => (await reprintsOf(page, printed.invoice)).length).toBe(1);
        expect(await printsOf(page, printed.invoice)).toHaveLength(2);
        expect(await printsOf(page, unprinted.invoice)).toEqual([]);
        expect(posted.count).toBe(0);
        expect(arrange('kitchen.items', { invoice: printed.invoice }).statuses).toEqual(['accepted']);
    });
});
