import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('25-cashier-shifts', () => {
    // Multi-page flows: the shared 4-CPU machine can take longer than the default 30 s.
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('another cashier\'s orders in the same outlet neither hold the close nor get cancelled', { tag: ['@REQ-003-AC-27'] }, async ({ asRole, arrange, uniq }) => {
        // The manager works a second till: food not handed over, a guest's unpaid bill and a waiting Pay now sale.
        const other = 'manager@e2e.test';
        const food = arrange('kitchen-sale', { prefix: uniq('Lain'), cashier: other });
        const bill = arrange('posops.sale', { cashier: other, guest: 1, qty: 1, left: 9, title: uniq('Bon') });
        const waiting = arrange('posretry.failed-sale', { cashier: other, status: 'pending', qty: 2 });
        const { shift_id: otherShift } = arrange('shiftclose.state', { invoice: food.invoice });

        const { shift_id } = arrange('open-shift', { cash: 100000 });
        const page = await asRole(cashier);
        await page.goto(`/dashboard/cashier-shifts/${shift_id}`);
        await expect(page.getByText('Shift Aktif', { exact: true })).toBeVisible();
        await expect(page.getByRole('region', { name: 'Belum selesai' })).toHaveCount(0);
        await page.getByRole('spinbutton').fill('100000');
        await page.getByRole('button', { name: 'Finalisasi Closing' }).click();
        await expect(page.getByText('Shift Closed', { exact: true })).toBeVisible();

        expect(arrange('shiftclose.state', { shift_id })).toMatchObject({ status: 'closed', unpaid_bills_total: 0, cash_difference: 0 });
        expect(arrange('shiftclose.state', { shift_id: otherShift }).status).toBe('open');
        expect(arrange('kitchen.items', { invoice: food.invoice }).statuses).toEqual(['accepted']);
        expect(arrange('paylater.sale-state', { invoice: bill.invoice }).payment_status).toBe('unpaid');
        expect(arrange('shiftclose.sale-state', { invoice: waiting.invoice })).toMatchObject({ payment_status: 'pending', product_stock: 8, warehouse_stock: 8, restocks: 0 });
    });
});
