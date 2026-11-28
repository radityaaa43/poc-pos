import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { roleOf } from '../../support/crm.js';
import { chooseCustomer, finish, openPos, total } from '../../support/pos.js';
import { field } from '../../support/inventory.js';

test.describe('POS: a denied credit exception', () => {
    test.describe.configure({ timeout: 240_000 });

    test('shows the cashier the reason, and the basket is resumed and paid now', { tag: ['@REQ-007-AC-06'] }, async ({ asRole, arrange, uniq }) => {
        const { product_id } = arrange('product', { title: uniq('tolak'), stock: 20 });
        const { customer_id, name } = arrange('pos.customer', { name: uniq('Ditolak') });
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
        await page.getByRole('button', { name: 'Selesaikan Transaksi' }).click();
        await page.getByRole('dialog').getByRole('button', { name: 'Minta pengecualian' }).click();
        await expect(page.getByRole('dialog')).toHaveCount(0);

        const manager = await asRole(roleOf(roles, 'manager'));
        await manager.goto('/dashboard/discount-approvals');
        const card = manager.getByRole('listitem').filter({ hasText: name });
        await expect(card).toContainText('Lunasi piutang yang lewat jatuh tempo dulu');
        await card.getByLabel('Alasan').fill('Tagih yang lama dulu');
        await card.getByRole('button', { name: 'Tolak' }).click();
        await expect(card).toHaveCount(0);

        await openPos(page);
        await page.getByRole('button', { name: /Transaksi Ditahan/ }).click();
        await expect(page.getByText('Pengecualian kredit ditolak')).toBeVisible();
        await expect(page.getByText('Tagih yang lama dulu')).toBeVisible();
        await page.getByRole('button', { name: 'Lanjutkan', exact: true }).click();
        // The basket is back before cash is counted against it.
        await expect(total(page)).toHaveText(/Rp\s*100\.000/);
        await page.getByRole('button', { name: 'Uang pas' }).click();
        const invoice = await finish(page);

        expect(arrange('paylater.sale-state', { invoice })).toMatchObject({ payment_status: 'paid', receivables: 0 });
        expect(arrange('paylater.credit-requests', { customer_id })).toMatchObject({ requests: [{ status: 'denied', invoice: null }] });
        // Only the arranged debt: the denial booked nothing on credit.
        expect(arrange('paylater.credit-state', { customer_id })).toMatchObject({ receivables: 1 });
    });
});
