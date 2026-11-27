import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { setUpPrintDevice } from '../../support/kitchen.js';
import { fakePrinter, setUserAgent, sentToPrinter, WINDOWS_UA, LINUX_UA } from '../../support/webusb.js';

const cashier = roles.find((role) => role.key === 'cashier');

const LOCKED = 'Printer tidak bisa dipakai dari browser — ganti driver ke WinUSB';
const banner = (page, text) => page.getByRole('alert').filter({ hasText: text });

test.describe('33-kitchen-printer', () => {
    test.describe.configure({ timeout: 240_000 });

    test('on Windows a printer the driver holds shows the WinUSB message and the steps in the app', { tag: ['@REQ-004-AC-11'] }, async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        const page = await asRole(cashier);
        await setUserAgent(page, WINDOWS_UA);
        await fakePrinter(page, { fault: 'claim-network' }); // claimInterface fails with NetworkError, as on the default driver
        await page.goto('/dashboard/kitchen');
        await page.getByRole('button', { name: 'Pengaturan printer dapur' }).click();
        const panel = page.getByRole('region', { name: 'Pengaturan printer dapur' });
        await panel.getByLabel('Nama perangkat cetak').fill(uniq('Dapur'));
        await panel.getByRole('button', { name: 'Simpan' }).click();
        await expect(panel.getByRole('button', { name: 'Hubungkan printer' })).toBeEnabled();
        await panel.getByRole('button', { name: 'Hubungkan printer' }).click();

        // The settings panel says it at once; nothing connected.
        await expect(panel.getByText(LOCKED)).toBeVisible();
        await expect(panel.getByRole('button', { name: 'Hubungkan printer' })).toBeVisible();

        // With Cetak otomatis on, the display's one alert carries the same message.
        await panel.getByRole('switch', { name: 'Cetak otomatis' }).click();
        const alert = banner(page, LOCKED);
        await expect(alert).toBeVisible();
        const toggle = alert.getByRole('button', { name: 'Lihat langkah' });
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await expect(alert.getByRole('listitem')).toHaveCount(0);

        // The five steps are shown here, in the app, and there is nothing to click through to.
        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await expect(alert.getByRole('listitem')).toHaveCount(5);
        await expect(alert.getByRole('listitem').first()).toContainText('Zadig');
        await expect(alert.getByRole('link')).toHaveCount(0);
        await expect(alert.locator('a')).toHaveCount(0);

        // A driver lock needs the person: it is not retried and nothing is sent.
        expect(await sentToPrinter(page)).toEqual([]);
    });

    test('the same error off Windows is a plain print failure, without the WinUSB text', { tag: ['@REQ-004-AC-11'] }, async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        const page = await asRole(cashier);
        await setUserAgent(page, LINUX_UA);
        await fakePrinter(page);
        await setUpPrintDevice(page, uniq('Dapur')); // connects fine, Cetak otomatis on

        // The printer is held by another program after a reload: claimInterface fails when the app re-attaches it.
        await fakePrinter(page, { granted: true, fault: 'claim-network' });
        await page.reload();
        const sale = arrange('kitchen-sale', { prefix: uniq('Ctk') });

        await expect(banner(page, 'Gagal mencetak — coba lagi')).toBeVisible({ timeout: 20_000 });
        await expect(page.locator('article', { hasText: sale.invoice }).getByText('Belum tercetak')).toBeVisible();
        await expect(page.getByText('ganti driver ke WinUSB')).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Lihat langkah' })).toHaveCount(0);
    });
});
