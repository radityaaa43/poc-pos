import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { addProduct, cartTotal, openPos, pickCustomer, roleOf, rupiah } from '../../support/crm.js';

test.describe('price lists: price in the POS cart', () => {
    test('a member pays the price-list price, a plain customer the normal price', async ({ asRole, arrange, uniq }) => {
        const [title, memberName, plainName] = [uniq('daftar-harga'), uniq('Member'), uniq('Biasa')];
        arrange('crm.pos-ready');
        const { product_id } = arrange('crm.product', { title, price: 10000 });
        arrange('crm.price-list', { name: uniq('Harga Member'), product_id, price: 7500 });
        arrange('crm.customer', { name: memberName, member: 1 });
        arrange('crm.customer', { name: plainName });

        const page = await asRole(roleOf(roles, 'cashier'));
        await openPos(page);
        await pickCustomer(page, plainName);
        await addProduct(page, title);
        await expect(cartTotal(page)).toHaveText(rupiah(10000));

        // Same cart, member selected: the member price list takes over.
        await page.getByRole('button', { name: new RegExp(plainName) }).first().click();
        await page.getByPlaceholder('Cari nama/telepon/nomor anggota...').fill(memberName);
        await page.getByRole('button', { name: new RegExp(memberName) }).click();
        await expect(cartTotal(page)).toHaveText(rupiah(7500));
    });
});
