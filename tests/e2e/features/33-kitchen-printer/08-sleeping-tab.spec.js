import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { sentWith, setUpPrintDevice } from '../../support/kitchen.js';
import { fakePrinter, sentToPrinter, asText, fakeWakeLock, removeWakeLock, wakeLockState } from '../../support/webusb.js';

const cashier = roles.find((role) => role.key === 'cashier');

const isTicketsPoll = (url) => /\/kitchen\/print-devices\/\d+\/tickets$/.test(url);
const isPrintRecord = (request) => request.method() === 'POST' && /\/kitchen\/print-devices\/\d+\/prints$/.test(request.url());
const printsOf = async (page, invoice) => (await sentToPrinter(page)).map(asText).filter((text) => text.includes(invoice));
const banner = (page, text) => page.getByRole('alert').filter({ hasText: text });

test.describe('33-kitchen-printer', () => {
    test.describe.configure({ timeout: 240_000 });

    test('a tab that slept shows the offline warning when it wakes to an old order, then prints it once', { tag: ['@REQ-004-AC-10'] }, async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        const page = await asRole(cashier);
        await fakePrinter(page);
        await fakeWakeLock(page);
        await setUpPrintDevice(page, uniq('Dapur'));

        // The tab "sleeps": its ticket polls hang, so the order sits unprinted. Real time is used on purpose
        // (the order's age comes from the server clock), 35 s being past the 30 s the warning waits for.
        let wake;
        const asleep = new Promise((resolve) => { wake = resolve; });
        await page.route((url) => isTicketsPoll(url.pathname), async (route) => {
            await asleep;
            await route.continue().catch(() => {});
        });
        // The record is held too, so the ticket stays "unprinted" long enough to be seen on the page.
        let recordHeld;
        const recordGate = new Promise((resolve) => { recordHeld = resolve; });
        await page.route((url) => /\/kitchen\/print-devices\/\d+\/prints$/.test(url.pathname), async (route) => {
            if (route.request().method() !== 'POST') return route.continue();
            await recordGate;
            await route.continue().catch(() => {});
        });

        const sale = arrange('kitchen-sale', { prefix: uniq('Ctk') });
        await page.waitForTimeout(35_000);
        expect(await printsOf(page, sale.invoice)).toEqual([]);

        // It wakes: the backlog arrives, its order is older than 30 s, so the warning shows while it prints.
        wake();
        await expect.poll(() => sentWith(page, sale.invoice), { timeout: 15_000 }).toBeDefined();
        await expect(banner(page, 'Printer dapur offline atau tab tidur')).toBeVisible();

        // Nothing prints twice: the record goes through and the ticket went out once.
        const recorded = page.waitForResponse((response) => isPrintRecord(response.request()), { timeout: 15_000 });
        recordHeld();
        await recorded;
        expect(await printsOf(page, sale.invoice)).toHaveLength(1);
        expect(arrange('kitchen.items', { invoice: sale.invoice }).statuses).toEqual(['accepted']);
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
