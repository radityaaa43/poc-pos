import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { removeWebUsb } from '../../support/webusb.js';

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('33-kitchen-printer', () => {
    test.describe.configure({ timeout: 240_000 });

    test('a device without WebUSB says so in the printer settings and the display keeps working', { tag: ['@REQ-004-AC-09'] }, async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        const page = await asRole(cashier);
        await removeWebUsb(page); // an iPad, Firefox or Safari: navigator.usb does not exist
        await page.goto('/dashboard/kitchen');
        await page.getByRole('button', { name: 'Pengaturan printer dapur' }).click();
        const panel = page.getByRole('region', { name: 'Pengaturan printer dapur' });

        // The device can still be named, but nothing that needs a printer works, and the panel says why.
        await panel.getByLabel('Nama perangkat cetak').fill(uniq('Dapur'));
        await panel.getByRole('button', { name: 'Simpan' }).click();
        await expect(panel.getByText('Perangkat ini tidak mendukung printer USB')).toBeVisible();
        await expect(panel.getByRole('button', { name: 'Hubungkan printer' })).toBeDisabled();
        await expect(panel.getByRole('switch', { name: 'Cetak otomatis' })).toBeDisabled();

        // The display itself works as usual: a new order is listed, with no printer banner on the page.
        const sale = arrange('kitchen-sale', { prefix: uniq('Ctk') });
        const card = page.locator('article', { hasText: sale.invoice });
        await expect(card).toBeVisible({ timeout: 15_000 });
        await expect(card).toContainText('Diterima');
        await expect(page.getByRole('alert').filter({ hasText: /printer/i })).toHaveCount(0);
    });
});
