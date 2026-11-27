import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { sentWith, setUpPrintDevice } from '../../support/kitchen.js';
import { fakePrinter, sentToPrinter, asText, setPrinterFault, unplugPrinter, replugPrinter } from '../../support/webusb.js';

const cashier = roles.find((role) => role.key === 'cashier');

const isPrintRecord = (response) => response.request().method() === 'POST' && /\/kitchen\/print-devices\/\d+\/prints$/.test(response.url());
const printsOf = async (page, invoice) => (await sentToPrinter(page)).map(asText).filter((text) => text.includes(invoice));
// The one printer banner: the older "Koneksi terputus" banner is also an alert, so pick by its text.
const banner = (page, text) => page.getByRole('alert').filter({ hasText: text });

// A sale whose first print fails (transfer fault on), on a printer the browser has already been granted: after a
// failed send the app looks the printer up again with getDevices(), as it does for a real one.
async function failedSale({ asRole, arrange, uniq }) {
    arrange('open-shift', { cash: 100000 });
    const page = await asRole(cashier);
    await fakePrinter(page, { granted: true });
    await setUpPrintDevice(page, uniq('Dapur'));
    await setPrinterFault(page, 'transfer');

    const sale = arrange('kitchen-sale', { prefix: uniq('Ctk') });
    const card = page.locator('article', { hasText: sale.invoice });
    await expect(banner(page, 'Gagal mencetak — coba lagi')).toBeVisible({ timeout: 15_000 });
    // BR-1: the order stays on the display, marked, and nothing reached the printer.
    await expect(card).toBeVisible();
    await expect(card.getByText('Belum tercetak')).toBeVisible();
    expect(await printsOf(page, sale.invoice)).toEqual([]);

    return { page, sale, card };
}

// Printed exactly once, and the warning and the chip are gone.
async function expectPrintedOnce(page, sale, card) {
    await expect.poll(() => sentWith(page, sale.invoice), { timeout: 15_000 }).toBeDefined();
    await expect(banner(page, 'Gagal mencetak — coba lagi')).toHaveCount(0);
    await expect(card.getByText('Belum tercetak')).toHaveCount(0);
    const tickets = await printsOf(page, sale.invoice);
    expect(tickets).toHaveLength(1);
    expect(tickets[0]).not.toContain('CETAK ULANG');
}

test.describe('33-kitchen-printer', () => {
    test.describe.configure({ timeout: 240_000 });

    test('a failed send warns, keeps the order on the display, and the next poll prints it once', { tag: ['@REQ-004-AC-08'] }, async ({ asRole, arrange, uniq }) => {
        const { page, sale, card } = await failedSale({ asRole, arrange, uniq });

        // The printer is back: the next 5-second poll retries the ticket by itself, and the server records it.
        const recorded = page.waitForResponse(isPrintRecord, { timeout: 20_000 });
        await setPrinterFault(page, 'none');
        await recorded;
        await expectPrintedOnce(page, sale, card);
        expect(arrange('kitchen.items', { invoice: sale.invoice }).statuses).toEqual(['accepted']);
    });

    test('Coba lagi prints the original ticket once', { tag: ['@REQ-004-AC-08'] }, async ({ asRole, arrange, uniq }) => {
        const { page, sale, card } = await failedSale({ asRole, arrange, uniq });

        // Hold the next poll's request, so only the button can print (an earlier poll has long been answered).
        const held = [];
        const ticketsUrl = /\/kitchen\/print-devices\/\d+\/tickets$/;
        await page.route(ticketsUrl, (route) => held.push(route));
        await expect.poll(() => held.length, { timeout: 15_000 }).toBeGreaterThan(0);

        // A chipped card offers Coba lagi, not Cetak ulang.
        await expect(card.getByRole('button', { name: 'Cetak ulang' })).toHaveCount(0);
        await setPrinterFault(page, 'none');
        await card.getByRole('button', { name: 'Coba lagi' }).click();
        await expectPrintedOnce(page, sale, card);
        await expect(card.getByRole('button', { name: 'Coba lagi' })).toHaveCount(0);
        // Once it printed, the card offers Cetak ulang instead.
        await expect(card.getByRole('button', { name: 'Cetak ulang' })).toBeVisible();

        // The held requests answer after the print: nothing is printed a second time.
        const polled = page.waitForResponse((response) => ticketsUrl.test(response.url()), { timeout: 15_000 });
        await page.unroute(ticketsUrl);
        await Promise.all(held.map((route) => route.continue().catch(() => {})));
        await polled;
        await page.waitForResponse((response) => ticketsUrl.test(response.url()), { timeout: 15_000 });
        expect(await printsOf(page, sale.invoice)).toHaveLength(1);
    });

    test('an unplugged printer shows Printer dapur tidak terhubung, and plugging it back prints the order', { tag: ['@REQ-004-AC-08'] }, async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        const page = await asRole(cashier);
        await fakePrinter(page, { granted: true });
        await setUpPrintDevice(page, uniq('Dapur'));
        await expect(banner(page, 'Printer dapur tidak terhubung')).toHaveCount(0);

        // BR-3: the disconnect event is enough, before any ticket has failed.
        await unplugPrinter(page);
        await expect(banner(page, 'Printer dapur tidak terhubung')).toBeVisible();

        // An order that arrives meanwhile stays on the display, marked, and is not lost.
        const sale = arrange('kitchen-sale', { prefix: uniq('Ctk') });
        const card = page.locator('article', { hasText: sale.invoice });
        await expect(card.getByText('Belum tercetak')).toBeVisible({ timeout: 15_000 });
        expect(await printsOf(page, sale.invoice)).toEqual([]);

        await replugPrinter(page);
        await expect.poll(() => sentWith(page, sale.invoice), { timeout: 15_000 }).toBeDefined();
        await expect(banner(page, 'Printer dapur tidak terhubung')).toHaveCount(0);
        await expect(card.getByText('Belum tercetak')).toHaveCount(0);
        expect(await printsOf(page, sale.invoice)).toHaveLength(1);
    });
});
