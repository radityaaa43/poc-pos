import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { addProduct, cartTotal, field, openPos, pickCustomer, roleOf, rupiah } from '../../support/crm.js';

test.describe('pricing rules: create a rule', () => {
    // Bug #101: the form always sends hidden default rows that fail validation, so nothing is saved and no error shows.
    test.fixme('a manager creates a product discount, previews it, and the POS cart uses it', async ({ asRole, arrange, uniq }) => {
        const [title, ruleName, customerName] = [uniq('promo-produk'), uniq('Diskon 15'), uniq('Pelanggan')];
        arrange('crm.pos-ready');
        arrange('crm.product', { title, price: 20000 });
        arrange('crm.customer', { name: customerName });

        const manager = await asRole(roleOf(roles, 'manager'));
        await manager.goto('/dashboard/pricing-rules');
        await manager.getByRole('link', { name: 'Buat Rule' }).click();
        await expect(manager).toHaveURL(/pricing-rules\/create$/);

        await field(manager, 'Nama Rule').fill(ruleName);
        await field(manager, 'Target Rule').selectOption({ label: 'Produk Tertentu' });
        await field(manager, 'Produk').selectOption({ label: title });
        await field(manager, 'Nilai Diskon').fill('15');

        // The draft preview prices a sample cart of the target product: 15% of Rp 20.000.
        await manager.getByRole('button', { name: 'Jalankan Preview' }).click();
        await expect(manager.getByText('Promo discount').locator('xpath=following-sibling::p')).toHaveText(rupiah(3000));

        await manager.getByRole('button', { name: 'Simpan Rule' }).click();
        await expect(manager).toHaveURL(/dashboard\/pricing-rules$/);
        await manager.getByPlaceholder('Cari nama rule...').fill(ruleName);
        const row = manager.getByRole('row').filter({ hasText: ruleName });
        await expect(row).toContainText('Standar');
        await expect(row).toContainText('active');

        // The cashier sees the saved rule at the till.
        const cashier = await asRole(roleOf(roles, 'cashier'));
        await openPos(cashier);
        await pickCustomer(cashier, customerName);
        await addProduct(cashier, title);
        await expect(cartTotal(cashier)).toHaveText(rupiah(17000));
        await expect(cashier.getByText(ruleName).first()).toBeVisible();
    });
});
