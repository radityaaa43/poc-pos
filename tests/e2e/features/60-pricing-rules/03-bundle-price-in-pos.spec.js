import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { addProduct, cartTotal, openPos, pickCustomer, roleOf, rupiah } from '../../support/crm.js';

test.describe('pricing rules: bundle price in the POS cart', () => {
    test('two products bought together cost the bundle price', async ({ asRole, arrange, uniq }) => {
        const [titleA, titleB, ruleName, customerName] = [uniq('paket-a'), uniq('paket-b'), uniq('Paket'), uniq('Pelanggan')];
        arrange('crm.pos-ready');
        const a = arrange('crm.product', { title: titleA, price: 10000 });
        const b = arrange('crm.product', { title: titleB, price: 15000 });
        arrange('crm.bundle-rule', { name: ruleName, product_a: a.product_id, product_b: b.product_id, price: 20000 });
        arrange('crm.customer', { name: customerName });

        const page = await asRole(roleOf(roles, 'cashier'));
        await openPos(page);
        await pickCustomer(page, customerName);

        // Only one half of the bundle: full price, no promo group.
        await addProduct(page, titleA);
        await expect(cartTotal(page)).toHaveText(rupiah(10000));
        await expect(page.getByText('Grup Promo Aktif')).toHaveCount(0);

        // Both halves: Rp 25.000 becomes Rp 20.000 and the group is listed with its saving.
        await addProduct(page, titleB);
        await expect(cartTotal(page)).toHaveText(rupiah(20000));
        await expect(page.getByText('Grup Promo Aktif')).toBeVisible();
        await expect(page.getByText(ruleName).first()).toBeVisible();
        await expect(page.getByText('Subtotal Dasar').locator('xpath=following-sibling::span')).toHaveText(rupiah(25000));
        await expect(page.getByText('Promo Otomatis', { exact: true }).locator('xpath=following-sibling::span')).toHaveText(rupiah(5000));
    });
});
