import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

test.describe('20-po-purchased-by', () => {
    test('outlet-rejects-central-material: an outlet manager cannot order a material central buys', async ({ asRole, arrange, uniq }) => {
        const title = uniq('Biji Kopi');
        arrange('product', { title, purchased_by: 'central' });
        const page = await asRole(roles.find((role) => role.key === 'manager'));

        await page.goto('/dashboard/purchase-orders/create');
        const search = page.getByPlaceholder('Cari produk untuk ditambahkan...');

        // With no warehouse chosen the material is offered, and the line is added.
        await search.fill(title);
        await page.getByRole('button', { name: new RegExp(title) }).click();
        await expect(page.getByRole('cell', { name: new RegExp(title) })).toBeVisible();

        // Once the outlet's warehouse is chosen, the page no longer offers it...
        await page.locator('select').filter({ hasText: 'Pilih Gudang' }).selectOption({ label: 'WH-E2E — Gudang E2E' });
        await search.fill('');
        await search.fill(title);
        await expect(page.getByRole('button', { name: new RegExp(title) })).toHaveCount(0);

        // ...and the server rejects the line that is already in the list.
        await page.getByRole('button', { name: 'Simpan PO' }).click();
        await expect(page.getByText(`${title} dibeli oleh pusat; outlet tidak bisa memesannya.`)).toBeVisible();
        await expect(page).toHaveURL(/purchase-orders\/create/);
    });
});
