import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { sentWith, setUpPrintDevice } from '../../support/kitchen.js';
import { fakePrinter, sentToPrinter, asText } from '../../support/webusb.js';

const cashier = roles.find((role) => role.key === 'cashier');

const isTicketsPoll = (response) => response.request().method() === 'GET' && /\/kitchen\/print-devices\/\d+\/tickets$/.test(response.url());
const isPrintRecord = (response) => response.request().method() === 'POST' && /\/kitchen\/print-devices\/\d+\/prints$/.test(response.url());
const printsOf = async (page, invoice) => (await sentToPrinter(page)).map(asText).filter((text) => text.includes(invoice));
const reprintsOf = async (page, invoice) => (await printsOf(page, invoice)).filter((text) => text.includes('CETAK ULANG'));
const stored = (page, key) => page.evaluate((k) => localStorage.getItem(k), key);

test.describe('33-kitchen-printer', () => {
    test.describe.configure({ timeout: 240_000 });

    test('Cetak ulang prints a CETAK ULANG ticket any number of times and records nothing', { tag: ['@REQ-004-AC-06'] }, async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        const page = await asRole(cashier);
        await fakePrinter(page);
        await setUpPrintDevice(page, uniq('Dapur'));

        const recorded = page.waitForResponse(isPrintRecord);
        const sale = arrange('kitchen-sale', { prefix: uniq('Ctk') });
        await expect.poll(() => sentWith(page, sale.invoice), { timeout: 10_000 }).toBeDefined();
        await recorded;
        expect(await reprintsOf(page, sale.invoice)).toHaveLength(0);

        // From here on the server must hear nothing: a reprint is not a print record.
        let posts = 0;
        page.on('request', (request) => {
            if (request.method() === 'POST' && /\/kitchen\/print-devices\/\d+\/(cancellation-)?prints$/.test(request.url())) posts += 1;
        });

        const card = page.locator('article', { hasText: sale.invoice });
        await expect(card.getByText('Belum tercetak')).toHaveCount(0);
        for (const count of [1, 2]) {
            await card.getByRole('button', { name: 'Cetak ulang' }).click();
            await expect.poll(async () => (await reprintsOf(page, sale.invoice)).length).toBe(count);
        }

        // The original and two reprints; the reprints carry the marker and the same order.
        const tickets = await printsOf(page, sale.invoice);
        expect(tickets).toHaveLength(3);
        expect(tickets.filter((text) => text.includes('CETAK ULANG'))).toHaveLength(2);
        expect(tickets[0]).not.toContain('CETAK ULANG');
        for (const ticket of tickets.slice(1)) expect(ticket).toContain(`1 x ${sale.flagged[0]}`);

        // A further poll offers nothing new, nothing was posted or left unconfirmed, and the order did not move.
        const poll = page.waitForResponse(isTicketsPoll, { timeout: 15_000 });
        expect((await (await poll).json()).tickets).toEqual([]);
        expect(posts).toBe(0);
        expect(JSON.parse((await stored(page, 'kitchen.unconfirmed')) ?? '[]')).toEqual([]);
        expect(arrange('kitchen.items', { invoice: sale.invoice }).statuses).toEqual(['accepted']);

        // A reload prints nothing, the reprints included.
        await fakePrinter(page, { granted: true });
        const firstPoll = page.waitForResponse(isTicketsPoll);
        await page.reload();
        expect((await (await firstPoll).json()).tickets).toEqual([]);
        const secondPoll = page.waitForResponse(isTicketsPoll, { timeout: 15_000 });
        expect((await (await secondPoll).json()).tickets).toEqual([]);
        expect(await sentToPrinter(page)).toEqual([]);
        expect(arrange('kitchen.items', { invoice: sale.invoice }).statuses).toEqual(['accepted']);
    });

    test('with Cetak otomatis off, Cetak ulang still prints a marked ticket and records nothing', { tag: ['@REQ-004-AC-06'] }, async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        const page = await asRole(cashier);
        await fakePrinter(page);
        const panel = await setUpPrintDevice(page, uniq('Dapur'));
        await panel.getByRole('switch', { name: 'Cetak otomatis' }).click();
        await expect(panel.getByRole('switch', { name: 'Cetak otomatis' })).toHaveAttribute('aria-checked', 'false');

        let posts = 0;
        page.on('request', (request) => {
            if (request.method() === 'POST' && /\/kitchen\/print-devices\/\d+\/prints$/.test(request.url())) posts += 1;
        });
        const sale = arrange('kitchen-sale', { prefix: uniq('Ctk') });
        const card = page.locator('article', { hasText: sale.invoice });
        await expect(card).toBeVisible();
        // Nothing prints on its own, so no card is "Belum tercetak" and every card offers Cetak ulang.
        await expect(card.getByText('Belum tercetak')).toHaveCount(0);
        await card.getByRole('button', { name: 'Cetak ulang' }).click();

        await expect.poll(() => reprintsOf(page, sale.invoice).then((tickets) => tickets.length)).toBe(1);
        expect(await printsOf(page, sale.invoice)).toHaveLength(1);
        expect(posts).toBe(0);
        expect(arrange('kitchen.items', { invoice: sale.invoice }).statuses).toEqual(['accepted']);
    });
});
