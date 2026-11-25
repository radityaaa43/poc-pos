import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { addProduct, cartTotal, openPos, pickCustomer, roleOf, rupiah } from '../../support/crm.js';

test.describe('pricing rules: buy X get Y in the POS cart', () => {
    test('the reward product is free once the bought product is in the cart', async ({ asRole, arrange, uniq }) => {
        const [buyTitle, getTitle, ruleName, customerName] = [uniq('beli'), uniq('gratis'), uniq('BeliGratis'), uniq('Pelanggan')];
        arrange('crm.pos-ready');
        const buy = arrange('crm.product', { title: buyTitle, price: 10000 });
        const get = arrange('crm.product', { title: getTitle, price: 15000 });
        arrange('crm.buy-get-rule', { name: ruleName, buy_id: buy.product_id, get_id: get.product_id });
        arrange('crm.customer', { name: customerName });

        const page = await asRole(roleOf(roles, 'cashier'));
        await openPos(page);
        await pickCustomer(page, customerName);

        // The reward alone is not free: the rule needs the bought product too.
        await addProduct(page, getTitle);
        await expect(cartTotal(page)).toHaveText(rupiah(15000));

        await addProduct(page, buyTitle);
        await expect(cartTotal(page)).toHaveText(rupiah(10000));
        await expect(page.getByText('Grup Promo Aktif')).toBeVisible();
        await expect(page.getByText(ruleName).first()).toBeVisible();
        await expect(page.getByText('Promo Otomatis', { exact: true }).locator('xpath=following-sibling::span')).toHaveText(rupiah(15000));
    });
});
