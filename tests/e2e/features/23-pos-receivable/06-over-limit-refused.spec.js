import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { roleOf } from '../../support/crm.js';
import { chooseCustomer, finish, openPos, total } from '../../support/pos.js';
import { field } from '../../support/inventory.js';

test.describe('POS: pay later over the credit limit', () => {
    test.describe.configure({ timeout: 240_000 });

    test('is refused with what is left of the limit; within it the sale becomes a receivable', { tag: ['@REQ-007-AC-04'] }, async ({ asRole, arrange, uniq }) => {
        const { product_id } = arrange('product', { title: uniq('batas'), stock: 50 });
        const { customer_id, name } = arrange('pos.customer', { name: uniq('Kredit') });
        arrange('paylater.setting', { enabled: 1 });
        arrange('paylater.credit', { customer_id, limit: 500000 });
        arrange('posops.receivable', { customer_id, total: 300000, due_days: 14 });
        arrange('pos.open-shift', { cash: 100000 });
        arrange('pos.cart', { product_id, qty: 30 });

        const page = await asRole(roleOf(roles, 'cashier'));
        const payLater = async () => {
            await openPos(page);
            await chooseCustomer(page, name);
            // The visible label: the hidden checkbox can sit under the sticky total bar below the credit panel.
            await page.getByText('Bayar Belakangan', { exact: true }).click();
            await expect(page.getByRole('checkbox')).toBeChecked();
            await field(page, 'Tanggal Jatuh Tempo').fill(new Date(Date.now() + 14 * 86_400_000).toISOString().slice(0, 10));
        };

        await payLater();
        await expect(total(page)).toHaveText(/Rp\s*300\.000/);
        await page.getByRole('button', { name: 'Selesaikan Transaksi' }).click();
        await expect(page.getByText('Melebihi batas piutang — sisa Rp 200.000')).toBeVisible();
        // Refused: no sale, no receivable (the one of each is the arranged debt), and the cart is still there.
        expect(arrange('paylater.credit-state', { customer_id })).toMatchObject({ sales: 1, receivables: 1 });
        await expect(total(page)).toHaveText(/Rp\s*300\.000/);

        arrange('posops.clear-carts');
        arrange('pos.cart', { product_id, qty: 15 });
        await payLater();
        await expect(total(page)).toHaveText(/Rp\s*150\.000/);
        const invoice = await finish(page);

        expect(arrange('paylater.sale-state', { invoice })).toMatchObject({ payment_status: 'unpaid', receivables: 1 });
        expect(arrange('paylater.credit-state', { customer_id })).toMatchObject({ credit_limit: 500000, sales: 2, receivables: 2 });
    });
});
