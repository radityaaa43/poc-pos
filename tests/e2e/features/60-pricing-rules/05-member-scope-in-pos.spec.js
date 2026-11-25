import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { addProduct, cartTotal, openPos, pickCustomer, roleOf, rupiah } from '../../support/crm.js';

test.describe('pricing rules: customer scope in the POS cart', () => {
    test('a member-only discount applies to a member but not to a plain customer', async ({ asRole, arrange, uniq }) => {
        const [title, ruleName, memberName, plainName] = [uniq('harga-member'), uniq('Diskon Member'), uniq('Member'), uniq('Biasa')];
        arrange('crm.pos-ready');
        const { product_id } = arrange('crm.product', { title, price: 20000 });
        arrange('crm.standard-rule', { name: ruleName, product_id, percent: 10, scope: 'member' });
        arrange('crm.customer', { name: memberName, member: 1 });
        arrange('crm.customer', { name: plainName });

        const page = await asRole(roleOf(roles, 'cashier'));
        await openPos(page);
        await pickCustomer(page, plainName);
        await addProduct(page, title);
        await expect(cartTotal(page)).toHaveText(rupiah(20000));
        await expect(page.getByText(ruleName)).toHaveCount(0);

        // Switching the customer to the member re-prices the same cart: 10% off Rp 20.000.
        await page.getByRole('button', { name: new RegExp(plainName) }).first().click();
        await page.getByPlaceholder('Cari nama/telepon/nomor anggota...').fill(memberName);
        await page.getByRole('button', { name: new RegExp(memberName) }).click();
        await expect(cartTotal(page)).toHaveText(rupiah(18000));
        await expect(page.getByText(ruleName).first()).toBeVisible();
    });
});
