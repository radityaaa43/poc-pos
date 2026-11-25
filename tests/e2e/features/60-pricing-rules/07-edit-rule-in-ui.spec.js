import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { addProduct, cartTotal, field, openPos, pickCustomer, roleOf, rupiah } from '../../support/crm.js';

test.describe('pricing rules: edit', () => {
    // Bug #101: saving the edit form fails on the hidden default rows, the page stays on /edit with no message.
    test.fixme('editing the discount changes the cart price', async ({ asRole, arrange, uniq }) => {
        const [title, ruleName, customerName] = [uniq('edit-produk'), uniq('Diskon Edit'), uniq('Pelanggan')];
        arrange('crm.pos-ready');
        const { product_id } = arrange('crm.product', { title, price: 20000 });
        arrange('crm.standard-rule', { name: ruleName, product_id, percent: 10 });
        arrange('crm.customer', { name: customerName });

        const manager = await asRole(roleOf(roles, 'manager'));
        await manager.goto('/dashboard/pricing-rules');
        await manager.getByPlaceholder('Cari nama rule...').fill(ruleName);
        await manager.getByRole('row').filter({ hasText: ruleName }).locator('a[href*="/edit"]').click();

        await expect(field(manager, 'Nama Rule')).toHaveValue(ruleName);
        await field(manager, 'Nilai Diskon').fill('25');
        await manager.getByRole('button', { name: 'Simpan Rule' }).click();
        await expect(manager).toHaveURL(/dashboard\/pricing-rules$/);

        const cashier = await asRole(roleOf(roles, 'cashier'));
        await openPos(cashier);
        await pickCustomer(cashier, customerName);
        await addProduct(cashier, title);
        await expect(cartTotal(cashier)).toHaveText(rupiah(15000));
    });
});
