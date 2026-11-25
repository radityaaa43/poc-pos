import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { addProduct, cartTotal, openPos, pickCustomer, roleOf, rupiah } from '../../support/crm.js';

test.describe('pricing rules: qty break in the POS cart', () => {
    test('the wholesale price starts at the minimum quantity and the cart total follows', async ({ asRole, arrange, uniq }) => {
        const title = uniq('grosir');
        const ruleName = uniq('Grosir');
        const customerName = uniq('Pelanggan');
        arrange('crm.pos-ready');
        const { product_id } = arrange('crm.product', { title, price: 10000 });
        arrange('crm.qty-break-rule', { name: ruleName, product_id, min_qty: 3, price: 8000 });
        arrange('crm.customer', { name: customerName });

        const page = await asRole(roleOf(roles, 'cashier'));
        await openPos(page);
        await pickCustomer(page, customerName);
        await addProduct(page, title);

        // 1 x Rp 10.000: below the break, so no promo.
        await expect(cartTotal(page)).toHaveText(rupiah(10000));
        await expect(page.getByText(ruleName)).toHaveCount(0);

        await page.getByRole('button', { name: '+', exact: true }).click();
        await expect(cartTotal(page)).toHaveText(rupiah(20000));

        // 3 x Rp 8.000 = Rp 24.000 instead of Rp 30.000.
        await page.getByRole('button', { name: '+', exact: true }).click();
        await expect(cartTotal(page)).toHaveText(rupiah(24000));
        await expect(page.getByText(ruleName)).toBeVisible();
        await expect(page.getByText('Promo Otomatis', { exact: true })).toBeVisible();

        // Dropping back under the break removes the promo again.
        await page.getByRole('button', { name: '-', exact: true }).click();
        await expect(cartTotal(page)).toHaveText(rupiah(20000));
        await expect(page.getByText(ruleName)).toHaveCount(0);
    });
});
