import { test, expect } from '../../support/fixtures.js';

// The menu is a page the guest keeps open: the owner can switch Bayar Belakangan off after it loaded. The server
// refuses the order with the reason, and the guest can still send it as Bayar di Kasir (REQ-003 AC-04, BR-5).
test.describe('37-dine-guest-menu', () => {
    test('Bayar Belakangan turned off while the menu is open: the tap is refused with the reason and Bayar di Kasir still works', { tag: ['@REQ-003-AC-04'] }, async ({ page, arrange, uniq }) => {
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const title = uniq('Gado');
        arrange('product', { title, stock: 5 });

        arrange('paylater.setting', { enabled: 1 });
        try {
            await page.goto(`/dine/${token}`);
            const card = page.locator('div.rounded-xl', { has: page.getByRole('heading', { name: title, exact: true }) });
            await card.getByRole('button', { name: '+ Tambah' }).click();
            await page.getByRole('button', { name: /1 item/ }).click();
            await expect(page.getByRole('dialog').getByRole('button', { name: 'Pesan — Bayar Belakangan' })).toBeVisible();

            // The owner switches it off; the guest's page still shows the old button.
            arrange('paylater.setting', { enabled: 0 });
            await page.getByRole('dialog').getByRole('button', { name: 'Pesan — Bayar Belakangan' }).click();

            await expect(page.getByText('Bayar Belakangan tidak tersedia di outlet ini. Pilih Bayar di kasir.')).toBeVisible();
            await page.waitForLoadState('networkidle');
            await expect(page).toHaveURL(new RegExp(`/dine/${token}$`));
            expect(arrange('dine.table-state', { name: table_name }).orders).toBe(0);

            // The cart is kept: the guest reopens it and sends the order as Bayar di Kasir.
            await page.getByRole('button', { name: /1 item/ }).click();
            await page.getByRole('dialog').getByRole('button', { name: 'Pesan', exact: true }).click();
            await expect(page).toHaveURL(/\/dine-order\/[0-9a-f-]{36}$/);
            await expect(page.getByText('Menunggu Konfirmasi', { exact: true })).toBeVisible();
            expect(arrange('dine.table-state', { name: table_name }).orders).toBe(1);
        } finally {
            arrange('paylater.setting', { enabled: 0 });
        }
    });
});
