import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { sentWith, setUpPrintDevice } from '../../support/kitchen.js';
import { fakePrinter, sentToPrinter, asText, fakeWakeLock, removeWakeLock, wakeLockState } from '../../support/webusb.js';

const cashier = roles.find((role) => role.key === 'cashier');

const isTicketsPath = (path) => /\/kitchen\/print-devices\/\d+\/tickets$/.test(path);
const isRecordPath = (path) => /\/kitchen\/print-devices\/\d+\/prints$/.test(path);
const ticketTexts = async (page) => (await sentToPrinter(page)).map(asText);
const banner = (page, text) => page.getByRole('alert').filter({ hasText: text });

test.describe('33-kitchen-printer', () => {
    test.describe.configure({ timeout: 240_000 });

    test('a tab that slept warns on waking to old orders, then prints each once in arrival order', { tag: ['@REQ-004-AC-10'] }, async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        const page = await asRole(cashier);
        await fakePrinter(page);
        await fakeWakeLock(page);
        await setUpPrintDevice(page, uniq('Dapur'));

        // The tab "sleeps": its ticket polls hang, so the orders sit unprinted. Real time is used on purpose
        // (an order's age comes from the server clock), 35 s being past the 30 s the warning waits for.
        let wake;
        const asleep = new Promise((resolve) => { wake = resolve; });
        await page.route((url) => isTicketsPath(url.pathname), async (route) => {
            await asleep;
            await route.continue().catch(() => {});
        });
        // The record is held too, so the second ticket stays unprinted long enough for the warning to be seen.
        let release;
        const recordGate = new Promise((resolve) => { release = resolve; });
        await page.route((url) => isRecordPath(url.pathname), async (route) => {
            await recordGate;
            await route.continue().catch(() => {});
        });

        const first = arrange('kitchen-sale', { prefix: uniq('Ctk') });
        const second = arrange('kitchen-sale', { prefix: uniq('Ctk') });
        await page.waitForTimeout(35_000);
        expect(await ticketTexts(page)).toEqual([]);
        // No ticket request has been answered for 30 s: the warning does not wait for one.
        await expect(banner(page, 'Printer dapur offline atau tab tidur')).toBeVisible();

        // It wakes: the backlog arrives older than 30 s, so the warning shows while it prints.
        wake();
        await expect.poll(() => sentWith(page, first.invoice), { timeout: 15_000 }).toBeDefined();
        await expect(banner(page, 'Printer dapur offline atau tab tidur')).toBeVisible();

        release();
        await expect.poll(() => sentWith(page, second.invoice), { timeout: 15_000 }).toBeDefined();

        // The warning goes once printing has caught up (it is held 15 s so it does not flash).
        await expect(banner(page, 'Printer dapur offline atau tab tidur')).toHaveCount(0, { timeout: 30_000 });

        // Oldest first, and nothing twice, a few polls later too.
        const tickets = await ticketTexts(page);
        const at = (invoice) => tickets.findIndex((text) => text.includes(invoice));
        expect(at(first.invoice)).toBeLessThan(at(second.invoice));
        expect(tickets.filter((text) => text.includes(first.invoice))).toHaveLength(1);
        expect(tickets.filter((text) => text.includes(second.invoice))).toHaveLength(1);
        expect(arrange('kitchen.items', { invoice: second.invoice }).statuses).toEqual(['accepted']);
    });

    test('the device is kept awake while Cetak otomatis is on and released when it is off', { tag: ['@REQ-004-AC-10'] }, async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        const page = await asRole(cashier);
        await fakePrinter(page);
        await fakeWakeLock(page);
        const panel = await setUpPrintDevice(page, uniq('Dapur')); // ends with Cetak otomatis on

        await expect.poll(async () => (await wakeLockState(page)).held).toBe(true);
        const { requests } = await wakeLockState(page);
        expect(requests).toBeGreaterThan(0);
        // The lock works, so there is nothing to warn about.
        await expect(banner(page, 'Layar bisa tidur')).toHaveCount(0);

        await panel.getByRole('switch', { name: 'Cetak otomatis' }).click();
        await expect(panel.getByRole('switch', { name: 'Cetak otomatis' })).toHaveAttribute('aria-checked', 'false');
        await expect.poll(async () => (await wakeLockState(page)).held).toBe(false);
        await page.waitForTimeout(1_500);
        expect((await wakeLockState(page)).requests).toBe(requests);
    });

    test('a browser without the Wake Lock API warns that the screen can sleep, and still prints', { tag: ['@REQ-004-AC-10'] }, async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        const page = await asRole(cashier);
        await fakePrinter(page);
        await removeWakeLock(page);
        await setUpPrintDevice(page, uniq('Dapur'));

        await expect(banner(page, 'Layar bisa tidur — jaga perangkat tetap menyala')).toBeVisible();
        const sale = arrange('kitchen-sale', { prefix: uniq('Ctk') });
        await expect.poll(() => sentWith(page, sale.invoice), { timeout: 10_000 }).toBeDefined();
    });
});
