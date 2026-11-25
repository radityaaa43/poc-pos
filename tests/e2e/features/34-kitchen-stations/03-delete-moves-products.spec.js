import { test, expect } from '../../support/fixtures.js';
import { role, openEdit } from '../../support/inventory.js';
import { addToCart, chooseCustomer, finish, openPos, rupiah, total } from '../../support/pos.js';

// REQ-006 AC-03: deleting a station moves its products to Dapur, and items already in the kitchen keep the old name.
test.describe('34-kitchen-stations: delete a station', () => {
    test.describe.configure({ timeout: 240_000 });

    test('@REQ-006-AC-03 deleting a station moves its products to Dapur and leaves its kitchen items alone', async ({ asRole, arrange, uniq }) => {
        const dish = arrange('product', { title: uniq('Sate'), stock: 10 });
        arrange('kitchen.flag', { product_id: dish.product_id });
        const bar = arrange('kitchen.station', { name: uniq('Panggang'), products: dish.product_id });
        const customer = arrange('pos.customer', { name: uniq('Rina') }); // the Selesaikan button needs one (#133)
        arrange('pos.open-shift', { cash: 100000 });

        // A real paid sale, so the kitchen item gets the station at intake.
        const cashier = await asRole(role('cashier'));
        await openPos(cashier);
        await addToCart(cashier, dish.title);
        await chooseCustomer(cashier, customer.name);
        await expect(total(cashier)).toHaveText(rupiah(10000));
        await cashier.getByRole('button', { name: 'Uang pas' }).click();
        const invoice = await finish(cashier);
        expect(arrange('kitchen.items', { invoice })).toMatchObject({ count: 1, station_names: [bar.name] });

        const page = await asRole(role('manager'));
        await page.goto('/dashboard/settings/kitchen-stations');
        await page.getByRole('button', { name: `Hapus stasiun ${bar.name}` }).click();
        await page.locator('.swal2-popup').getByRole('button', { name: 'Hapus', exact: true }).click();
        await expect(page.getByRole('listitem').filter({ hasText: bar.name })).toHaveCount(0);

        await openEdit(page, dish.title);
        await expect(page.getByLabel('Perlu dibuat di dapur')).toBeChecked();
        await expect(page.locator('#kitchen_station_id').locator('option:checked')).toHaveText('Dapur');
        expect(arrange('kitchen.items', { invoice })).toMatchObject({ count: 1, station_names: [bar.name] });
    });
});
