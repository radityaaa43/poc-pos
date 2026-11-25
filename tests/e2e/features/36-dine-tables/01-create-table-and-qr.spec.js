import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');

test.describe('36-dine-tables', () => {
    test('create a table in an area, see it on the floor plan and in the list, open its QR and guest link', async ({ asRole, arrange, uniq }) => {
        const { area_name } = arrange('dine.table', { area: uniq('Area'), name: uniq('Lain') });
        const name = uniq('M');
        const page = await asRole(manager);

        await page.goto('/dashboard/dine-tables');
        await expect(page.getByText(/meja ditampilkan/)).toBeVisible();

        await page.getByRole('button', { name: 'Tambah Meja' }).first().click();
        const dialog = page.getByRole('dialog');
        await dialog.getByPlaceholder('Contoh: M1, Outdoor-1').fill(name);
        await dialog.locator('select').first().selectOption({ label: area_name });
        await dialog.locator('input[type=number]').first().fill('6'); // capacity
        await dialog.locator('select').nth(1).selectOption({ label: 'Bulat' });
        await dialog.getByRole('button', { name: 'Simpan' }).click();

        // Floor plan: the new table is a selectable shape with its area and capacity.
        await page.getByRole('button', { name: `${name}, aktif` }).click();
        await expect(page.getByText(`${area_name} · 6 orang · Aktif`)).toBeVisible();

        // List view shows the same row.
        await page.getByRole('button', { name: 'Daftar' }).click();
        const row = page.getByRole('row').filter({ hasText: name });
        await expect(row).toContainText(area_name);
        await expect(row).toContainText('6 org');
        await expect(row).toContainText('Aktif');

        // The link the QR encodes (`/dine/{token}`) opens the guest menu for this table.
        const { token } = arrange('dine.table-state', { name });
        const guest = await page.context().newPage();
        await guest.goto(`/dine/${token}`);
        await expect(guest.getByText(`Meja ${name} · ${area_name}`)).toBeVisible();
    });

    // Bug #118: QR PNG generation needs the imagick extension, which the app image and CI do not install: 500.
    test.fixme('the QR button opens a PNG of the table link', async ({ asRole, arrange, uniq }) => {
        const { table_name } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const page = await asRole(manager);
        await page.goto('/dashboard/dine-tables');
        await page.getByRole('button', { name: 'Daftar' }).click();

        const popupPromise = page.context().waitForEvent('page');
        await page.getByRole('button', { name: `Buka QR ${table_name}` }).click();
        const popup = await popupPromise;
        await popup.waitForURL(/\/dashboard\/dine-tables\/\d+\/qr$/);
        const qr = await page.request.get(popup.url());
        expect(qr.status()).toBe(200);
        expect(qr.headers()['content-type']).toContain('image/png');
    });
});
