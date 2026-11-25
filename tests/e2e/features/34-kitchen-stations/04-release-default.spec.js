import { test, expect } from '../../support/fixtures.js';
import { role } from '../../support/inventory.js';
import { addToCart, chooseCustomer, finish, openPos, rupiah, total } from '../../support/pos.js';

// REQ-006 AC-09, the clauses this ticket owns: Dapur exists out of the box and cannot be deleted, and a flagged product
// nobody mapped sells to Dapur. Clause 3 (every device starts on "Semua stasiun") needs the kitchen screen's station
// filter and is proven in #344 (S2). The e2e database is shared, so this asserts "present and not deletable", never "the only row".
test.describe('34-kitchen-stations: release default', () => {
    test.describe.configure({ timeout: 240_000 });

    test('@REQ-006-AC-09 Dapur is listed as the default station and cannot be deleted', async ({ asRole }) => {
        const page = await asRole(role('manager'));

        await page.goto('/dashboard/settings/kitchen-stations');
        const dapur = page.getByRole('listitem').filter({ hasText: 'Dapur' }).filter({ has: page.getByText('Bawaan', { exact: true }) });

        await expect(dapur).toHaveCount(1);
        await expect(dapur.getByRole('button', { name: /^Hapus stasiun / })).toHaveCount(0);
    });

    test('@REQ-006-AC-09 a flagged product with no station sells to the kitchen as Dapur', async ({ asRole, arrange, uniq }) => {
        const dish = arrange('product', { title: uniq('Soto'), stock: 10 });
        arrange('kitchen.flag', { product_id: dish.product_id }); // never mapped to a station
        const customer = arrange('pos.customer', { name: uniq('Budi') }); // the Selesaikan button needs one (#133)
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(role('cashier'));

        await openPos(page);
        await addToCart(page, dish.title);
        await chooseCustomer(page, customer.name);
        await expect(total(page)).toHaveText(rupiah(10000));
        await page.getByRole('button', { name: 'Uang pas' }).click();
        const invoice = await finish(page);

        expect(arrange('kitchen.items', { invoice })).toMatchObject({ count: 1, station_names: ['Dapur'] });
    });
});
