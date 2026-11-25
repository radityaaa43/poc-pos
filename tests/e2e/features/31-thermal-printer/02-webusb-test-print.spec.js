import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { fakePrinter, sentToPrinter, asText, INIT, CUT, DRAWER_KICK } from '../../support/webusb.js';

const manager = roles.find((role) => role.key === 'manager');

test.describe('thermal printer: WebUSB from settings', () => {
    test('connect, test print in the chosen paper width, open the drawer, disconnect', async ({ asRole }) => {
        test.setTimeout(120_000);
        const page = await asRole(manager);
        await fakePrinter(page);
        await page.goto('/dashboard/settings/printer');

        await page.getByRole('button', { name: 'Hubungkan Printer' }).click();
        await expect(page.getByText('Printer terhubung')).toBeVisible();

        // 58 mm: 32-character rules. The paper size is read from the form, saved or not.
        await page.locator('form select').selectOption('58mm');
        await page.getByRole('button', { name: 'Test Print' }).click();
        await expect(page.getByText('Test print terkirim')).toBeVisible();
        const [narrow] = await sentToPrinter(page);
        expect(narrow.slice(0, 2)).toEqual(INIT);
        expect(narrow.slice(-4)).toEqual(CUT);
        expect(asText(narrow)).toContain('TEST PRINT');
        expect(asText(narrow)).toContain('#TEST-001');
        expect(asText(narrow)).toContain('Produk Uji Cetak');
        expect(asText(narrow)).toContain(`\n${'-'.repeat(32)}\n`);
        expect(asText(narrow)).not.toContain('-'.repeat(33));

        await page.locator('form select').selectOption('80mm');
        await page.getByRole('button', { name: 'Test Print' }).click();
        await expect.poll(async () => (await sentToPrinter(page)).length).toBe(2);
        const wide = (await sentToPrinter(page))[1];
        expect(asText(wide)).toContain(`\n${'-'.repeat(48)}\n`);

        await page.getByRole('button', { name: 'Buka Laci (Kick)' }).click();
        await expect(page.getByText('Drawer dikick')).toBeVisible();
        expect((await sentToPrinter(page))[2]).toEqual(DRAWER_KICK);

        await page.getByRole('button', { name: 'Putuskan' }).click();
        await expect(page.getByRole('button', { name: 'Hubungkan Printer' })).toBeVisible();
    });
});
