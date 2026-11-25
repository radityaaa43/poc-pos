import { test, expect } from '../../support/fixtures.js';
import { role, openEdit } from '../../support/inventory.js';

const flag = (page) => page.getByLabel('Perlu dibuat di dapur');
const station = (page) => page.locator('#kitchen_station_id');

// Saves the open product form; the arranged product has no SKU and the form requires one.
async function save(page, uniq) {
    await page.getByPlaceholder('SKU unik').fill(uniq('SKU'));
    await page.getByRole('button', { name: 'Simpan Perubahan' }).click();
    await expect(page.getByText('Produk berhasil diperbarui')).toBeVisible();
}

// REQ-006 AC-02: each product that needs preparation goes to one station; with no choice it goes to Dapur.
test.describe('34-kitchen-stations: product station', () => {
    test('@REQ-006-AC-02 flagging a product shows the station select, and the chosen station is kept', async ({ asRole, arrange, uniq }) => {
        const bar = arrange('kitchen.station', { name: uniq('Bar') });
        const { title } = arrange('product', { title: uniq('Kopi'), stock: 5 });
        const page = await asRole(role('manager'));

        await openEdit(page, title);
        await expect(station(page)).toHaveCount(0); // hidden until the product is flagged
        await flag(page).check();
        await station(page).selectOption({ label: bar.name });
        await save(page, uniq);

        await openEdit(page, title);
        await expect(flag(page)).toBeChecked();
        await expect(station(page).locator('option:checked')).toHaveText(bar.name);
    });

    test('@REQ-006-AC-02 a flagged product with no station chosen shows Dapur', async ({ asRole, arrange, uniq }) => {
        const { title } = arrange('product', { title: uniq('Teh'), stock: 5 });
        const page = await asRole(role('manager'));

        await openEdit(page, title);
        await flag(page).check();
        await expect(station(page).locator('option:checked')).toHaveText('Dapur'); // preselected, nothing picked
        await save(page, uniq);

        await openEdit(page, title);
        await expect(station(page).locator('option:checked')).toHaveText('Dapur');
    });
});
