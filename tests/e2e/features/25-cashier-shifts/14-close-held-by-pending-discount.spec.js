import { roles } from '../../support/roles.js';
import { cashier, closeRefused, closeUnheld, describeShiftClose, expect, test } from '../../support/shift-close.js';

const manager = roles.find((role) => role.key === 'manager');

// #542 BR-1, BR-2, BR-4: a pending discount holds the close, is listed with its amount, and is decided from the list.
describeShiftClose(() => {
    test('#542 BR-1, BR-2, BR-4: a pending discount holds the close, the manager approves it from the list, and another shift is untouched', async ({ asRole, arrange, uniq }) => {
        const { shift_id } = arrange('open-shift', { cash: 100000 });
        // A second cashier's shift, so BR-4/AC-27 has a pending sale that must never show here.
        const other = arrange('open-shift', { cashier: 'manager@e2e.test' });
        const label = uniq('Meja');
        const pending = arrange('posops.pending-discount', { shift_id, total: 20000, discount: 5000, note: label });
        const elsewhere = arrange('posops.pending-discount', { shift_id: other.shift_id, cashier: 'manager@e2e.test' });

        // BR-1: order, label and the discount amount (not the grand total), read-only for a cashier.
        const page = await asRole(cashier);
        await page.goto(`/dashboard/cashier-shifts/${shift_id}`);
        const rows = page.getByRole('region', { name: 'Belum selesai' }).getByRole('listitem');
        await expect(rows).toHaveCount(1);
        await expect(rows).toContainText(pending.invoice);
        await expect(rows).toContainText(label);
        await expect(rows).toContainText(/Diskon menunggu persetujuan — Rp\s*5\.000/);
        await expect(rows.getByRole('button')).toHaveCount(0);
        await expect(rows).not.toContainText(elsewhere.invoice); // BR-4/AC-27: never the other shift's sale
        await closeRefused(page, '115000');

        // BR-2: a holder of discounts-approve decides from the list; the row disappears and the close goes through.
        const approval = await asRole(manager);
        await approval.goto(`/dashboard/cashier-shifts/${shift_id}`);
        approval.once('dialog', (dialog) => dialog.accept());
        await approval.getByRole('button', { name: `Setujui ${pending.invoice}` }).click();
        await expect(approval.getByRole('region', { name: 'Belum selesai' })).toHaveCount(0);
        await closeUnheld(page, shift_id);

        expect(arrange('shiftclose.state', { shift_id })).toMatchObject({ status: 'closed', cash_sales_total: 15000, expected_cash: 115000 });
        expect(arrange('shiftclose.sale-state', { invoice: pending.invoice })).toMatchObject({
            payment_status: 'paid',
            discount_approval_status: 'approved',
        });
        // BR-4: the other shift's sale is still open and waiting.
        expect(arrange('shiftclose.state', { shift_id: other.shift_id }).status).toBe('open');
        expect(arrange('shiftclose.sale-state', { invoice: elsewhere.invoice })).toMatchObject({
            payment_status: 'pending_approval',
            discount_approval_status: 'pending',
        });
    });
});
