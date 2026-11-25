import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { addProduct, cartTotal, openPos, pickCustomer, roleOf, rupiah } from '../../support/crm.js';

test.describe('pricing rules: delete', () => {
    test('deleting a rule removes the promo from the POS cart', async ({ asRole, arrange, uniq }) => {
        const [title, ruleName, customerName] = [uniq('hapus-produk'), uniq('Diskon Hapus'), uniq('Pelanggan')];
        arrange('crm.pos-ready');
        const { product_id } = arrange('crm.product', { title, price: 20000 });
        arrange('crm.standard-rule', { name: ruleName, product_id, percent: 10 });
        arrange('crm.customer', { name: customerName });

        const cashier = await asRole(roleOf(roles, 'cashier'));
        await openPos(cashier);
        await pickCustomer(cashier, customerName);
        await addProduct(cashier, title);
        await expect(cartTotal(cashier)).toHaveText(rupiah(18000));

        const manager = await asRole(roleOf(roles, 'manager'));
        await manager.goto('/dashboard/pricing-rules');
        await manager.getByPlaceholder('Cari nama rule...').fill(ruleName);
        const row = manager.getByRole('row').filter({ hasText: ruleName });
        await expect(row).toContainText('Standar');
        await row.getByRole('button').click();
        await manager.getByRole('button', { name: 'Ya, Hapus!' }).click();
        await expect(row).toHaveCount(0);

        await openPos(cashier);
        await expect(cartTotal(cashier)).toHaveText(rupiah(20000));
        await expect(cashier.getByText(ruleName)).toHaveCount(0);
    });
});
