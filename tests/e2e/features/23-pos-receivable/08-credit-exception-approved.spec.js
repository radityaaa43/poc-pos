import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { roleOf } from '../../support/crm.js';
import { chooseCustomer, openPos } from '../../support/pos.js';
import { field } from '../../support/inventory.js';

test.describe('POS: manager exception for a refused credit sale', () => {
    test.describe.configure({ timeout: 240_000 });

    test('is held while it waits, and approval books the receivable and the kitchen order without changing the limit', { tag: ['@REQ-007-AC-06'] }, async ({ asRole, arrange, uniq }) => {
        const { product_id } = arrange('product', { title: uniq('izin'), stock: 50 });
        arrange('kitchen.flag', { product_id });
        const { customer_id, name } = arrange('pos.customer', { name: uniq('Izin') });
        arrange('paylater.setting', { enabled: 1 });
        arrange('paylater.credit', { customer_id, limit: 100000 });
        arrange('pos.open-shift', { cash: 100000 });
        arrange('pos.cart', { product_id, qty: 30 });

        const page = await asRole(roleOf(roles, 'cashier'));
        await openPos(page);
        await chooseCustomer(page, name);
        await page.getByText('Bayar Belakangan', { exact: true }).click();
        await expect(page.getByRole('checkbox')).toBeChecked();
        await field(page, 'Tanggal Jatuh Tempo').fill(new Date(Date.now() + 14 * 86_400_000).toISOString().slice(0, 10));
        await page.getByRole('button', { name: 'Selesaikan Transaksi' }).click();

        const refusal = page.getByRole('dialog');
        await expect(refusal.getByText('Melebihi batas piutang — sisa Rp 100.000')).toBeVisible();
        await refusal.getByRole('button', { name: 'Minta pengecualian' }).click();
        await expect(page.getByRole('dialog')).toHaveCount(0);

        await page.getByRole('button', { name: /Transaksi Ditahan/ }).click();
        await expect(page.getByRole('button', { name: 'Menunggu persetujuan kredit' })).toBeDisabled();
        await expect(page.getByRole('button', { name: 'Hapus transaksi ditahan' })).toBeDisabled();
        // Held only: no sale, receivable or kitchen order.
        expect(arrange('paylater.credit-state', { customer_id })).toMatchObject({ sales: 0, receivables: 0 });
        expect(arrange('paylater.credit-requests', { customer_id })).toMatchObject({
            requests: [{ status: 'pending', amount: 300000, invoice: null }],
            kitchen_items: 0,
        });

        const manager = await asRole(roleOf(roles, 'manager'));
        await manager.goto('/dashboard/discount-approvals');
        const card = manager.getByRole('listitem').filter({ hasText: name });
        await expect(card).toContainText(/Rp\s*300\.000/);
        await expect(card).toContainText('Melebihi batas piutang — sisa Rp 100.000');
        await card.getByLabel('Alasan').fill('Pelanggan lama, bayar minggu depan');
        await card.getByRole('button', { name: 'Setujui' }).click();
        await expect(card).toHaveCount(0);

        expect(arrange('paylater.credit-state', { customer_id })).toMatchObject({ credit_limit: 100000, sales: 1, receivables: 1 });
        const state = arrange('paylater.credit-requests', { customer_id });
        expect(state).toMatchObject({
            requests: [{ status: 'approved', amount: 300000 }],
            kitchen_items: 1,
            audits: [
                { event: 'credit_approval.requested', user: 'cashier@e2e.test', amount: 300000 },
                { event: 'credit_approval.approved', user: 'manager@e2e.test', amount: 300000, reason: 'Pelanggan lama, bayar minggu depan' },
            ],
        });
        expect(arrange('paylater.sale-state', { invoice: state.requests[0].invoice })).toMatchObject({ payment_status: 'unpaid', receivables: 1 });
    });
});
