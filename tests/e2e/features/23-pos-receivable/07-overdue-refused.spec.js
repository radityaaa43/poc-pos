import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { roleOf } from '../../support/crm.js';
import { chooseCustomer, finish, openPos, total } from '../../support/pos.js';
import { field } from '../../support/inventory.js';

test.describe('POS: pay later while a receivable is overdue', () => {
    test.describe.configure({ timeout: 240_000 });

    test('is refused for a customer with a limit although it has room, and the sale can be paid now', { tag: ['@REQ-007-AC-05'] }, async ({ asRole, arrange, uniq }) => {
        const { product_id } = arrange('product', { title: uniq('lewat'), stock: 20 });
        const { customer_id, name } = arrange('pos.customer', { name: uniq('Nunggak') });
        arrange('paylater.setting', { enabled: 1 });
        arrange('paylater.credit', { customer_id, limit: 500000 });
        arrange('posops.receivable', { customer_id, total: 100000, due_days: -3 });
        arrange('pos.open-shift', { cash: 100000 });
        arrange('pos.cart', { product_id, qty: 10 });

        const page = await asRole(roleOf(roles, 'cashier'));
        await openPos(page);
        await chooseCustomer(page, name);
        await page.getByText('Bayar Belakangan', { exact: true }).click();
        await expect(page.getByRole('checkbox')).toBeChecked();
        await field(page, 'Tanggal Jatuh Tempo').fill(new Date(Date.now() + 14 * 86_400_000).toISOString().slice(0, 10));
        await expect(total(page)).toHaveText(/Rp\s*100\.000/);
        await page.getByRole('button', { name: 'Selesaikan Transaksi' }).click();

        const refusal = page.getByRole('dialog');
        await expect(refusal.getByText('Lunasi piutang yang lewat jatuh tempo dulu')).toBeVisible();
        expect(arrange('paylater.credit-state', { customer_id })).toMatchObject({ sales: 1, receivables: 1 });

        // The same cart, paid in cash instead: "Bayar sekarang" switches pay later off.
        await refusal.getByRole('button', { name: 'Bayar sekarang' }).click();
        await expect(page.getByRole('checkbox')).not.toBeChecked();
        await page.getByRole('button', { name: 'Uang pas' }).click();
        const invoice = await finish(page);

        expect(arrange('paylater.sale-state', { invoice })).toMatchObject({ payment_status: 'paid', receivables: 0 });
    });
});
