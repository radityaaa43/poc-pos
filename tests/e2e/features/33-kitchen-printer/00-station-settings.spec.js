import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { signIn } from '../../support/platform.js';
import { fakePrinter, sentToPrinter, asText, INIT, CUT, PRINTER_KEY } from '../../support/webusb.js';

const cashier = roles.find((role) => role.key === 'cashier');

const openPanel = async (page) => {
    await page.goto('/dashboard/kitchen');
    await page.getByRole('button', { name: 'Pengaturan printer dapur' }).click();

    return page.getByRole('region', { name: 'Pengaturan printer dapur' });
};
const stored = (page, key) => page.evaluate((k) => localStorage.getItem(k), key);

test.describe('33-kitchen-printer', () => {
    test.describe.configure({ timeout: 240_000 });

    test('name the station, connect the printer, test print per paper size, and keep the settings', { tag: ['@REQ-004-AC-07'] }, async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 }); // the display needs an outlet: the one of the cashier's open shift
        const name = uniq('Dapur');
        const page = await asRole(cashier);
        await fakePrinter(page);
        const panel = await openPanel(page);

        // Nothing but the name form works until the station has a name.
        await expect(panel.getByRole('button', { name: 'Hubungkan printer' })).toBeDisabled();
        await expect(panel.getByRole('switch', { name: 'Cetak otomatis' })).toBeDisabled();
        await panel.getByLabel('Nama stasiun').fill(name);
        await panel.getByRole('button', { name: 'Simpan' }).click();
        await expect(panel.getByText(name, { exact: true })).toBeVisible();
        expect(JSON.parse(await stored(page, 'kitchen.station')).name).toBe(name);

        // Another device cannot take the same name in this outlet, and saves nothing.
        const other = await asRole(cashier);
        const otherPanel = await openPanel(other);
        await otherPanel.getByLabel('Nama stasiun').fill(name);
        await otherPanel.getByRole('button', { name: 'Simpan' }).click();
        await expect(otherPanel.getByText('Nama stasiun sudah dipakai di outlet ini')).toBeVisible();
        expect(await stored(other, 'kitchen.station')).toBeNull();

        await panel.getByRole('button', { name: 'Hubungkan printer' }).click();
        await expect(panel.getByRole('button', { name: 'Putuskan' })).toBeVisible();
        expect(await stored(page, 'kitchen.printerKey')).toBe(PRINTER_KEY);
        // Connected, but auto-print is still off, so the panel does not say "ready".
        await expect(panel.getByRole('status')).toHaveText('Printer belum terhubung');

        // 58 mm: 32-character rules; 80 mm: 48.
        const paper = panel.getByLabel('Ukuran kertas (58 mm / 80 mm)');
        await paper.selectOption('58mm');
        await panel.getByRole('button', { name: 'Cetak percobaan' }).click();
        await expect.poll(async () => (await sentToPrinter(page)).length).toBe(1);
        const [narrow] = await sentToPrinter(page);
        expect(narrow.slice(0, 2)).toEqual(INIT);
        expect(narrow.slice(-4)).toEqual(CUT);
        expect(asText(narrow)).toContain('TEST');
        expect(asText(narrow)).toContain('1 x Test');
        expect(asText(narrow)).toContain(`\n${'-'.repeat(32)}\n`);
        expect(asText(narrow)).not.toContain('-'.repeat(33));

        await paper.selectOption('80mm');
        await panel.getByRole('button', { name: 'Cetak percobaan' }).click();
        await expect.poll(async () => (await sentToPrinter(page)).length).toBe(2);
        expect(asText((await sentToPrinter(page))[1])).toContain(`\n${'-'.repeat(48)}\n`);

        // Paper size and auto-print stay on this device across a reload (the printer was granted earlier, so it is listed again).
        await paper.selectOption('58mm');
        await panel.getByRole('switch', { name: 'Cetak otomatis' }).click();
        await expect(panel.getByRole('status')).toHaveText('Printer siap');
        await fakePrinter(page, { granted: true });
        await page.reload();
        await page.getByRole('button', { name: 'Pengaturan printer dapur' }).click();
        const reloaded = page.getByRole('region', { name: 'Pengaturan printer dapur' });
        await expect(reloaded.getByText(name, { exact: true })).toBeVisible();
        await expect(reloaded.getByRole('button', { name: 'Putuskan' })).toBeVisible();
        await expect(reloaded.getByLabel('Ukuran kertas (58 mm / 80 mm)')).toHaveValue('58mm');
        await expect(reloaded.getByRole('switch', { name: 'Cetak otomatis' })).toHaveAttribute('aria-checked', 'true');
        await expect(reloaded.getByRole('status')).toHaveText('Printer siap');

        // Auto-print off: a sale that reaches the kitchen prints nothing.
        await reloaded.getByRole('switch', { name: 'Cetak otomatis' }).click();
        await expect(reloaded.getByRole('switch', { name: 'Cetak otomatis' })).toHaveAttribute('aria-checked', 'false');
        const sale = arrange('kitchen-sale', { prefix: uniq('Ctk') });
        await expect(page.locator('article', { hasText: sale.invoice })).toBeVisible();
        await page.waitForTimeout(6_000); // a negative check: longer than one 5 s ticket poll
        expect(await sentToPrinter(page)).toEqual([]);

        await reloaded.getByRole('button', { name: 'Putuskan' }).click();
        await expect(reloaded.getByRole('button', { name: 'Hubungkan printer' })).toBeVisible();
        expect(await stored(page, 'kitchen.printerKey')).toBeNull();
        await expect(reloaded.getByRole('status')).toHaveText('Printer belum terhubung');
    });

    test('the settings panel switches to English', { tag: ['@REQ-004-BR-10'] }, async ({ page, arrange, uniq }) => {
        // Own user: choosing a language is saved on the account, so a shared role must not take it.
        const { email } = arrange('dine.user', { email: `${uniq('dapur')}@e2e.test`, permissions: 'dashboard-access,dine-orders-prepare' });
        await signIn(page, email);
        await openPanel(page);

        await page.getByTitle('Change Language').click();
        // Switching the language reloads the page (LanguageSwitcher), which closes the panel: wait for the reload, then open it again.
        await Promise.all([page.waitForEvent('load'), page.getByRole('menuitem', { name: /English/ }).click()]);
        await page.getByRole('button', { name: 'Kitchen printer settings' }).click();
        const panel = page.getByRole('region', { name: 'Kitchen printer settings' });
        await expect(panel).toBeVisible();
        await expect(page.getByRole('button', { name: 'Kitchen printer settings' })).toBeVisible();
        await expect(panel.getByLabel('Station name')).toBeVisible();
        await expect(panel.getByRole('button', { name: 'Connect printer' })).toBeVisible();
        await expect(panel.getByRole('switch', { name: 'Auto-print' })).toBeVisible();
    });
});
