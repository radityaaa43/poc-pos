import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');

test.describe('38-dine-orders-queue', () => {
    // Question #128: nothing in the app takes payment for an accepted dine order or completes it
    // (docs/features/dine-in.md says the cashier confirms it on the POS page). Written against the
    // documented status flow; revise once the Owner answers.
    test.fixme('an accepted order can be settled by the cashier and then completes', async ({ asRole, page: guest, arrange, uniq }) => {
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const { product_id } = arrange('product', { title: uniq('Rujak'), stock: 5 });
        const { access_token } = arrange('dine.order', { token, product_id, qty: 1 });
        arrange('open-shift', { cashier: manager.email, cash: 100000 });

        const page = await asRole(manager);
        await page.goto('/dashboard/dine-orders');
        page.once('dialog', (confirm) => confirm.accept());
        await page.locator('div.rounded-2xl', { hasText: table_name }).getByRole('button', { name: 'Terima' }).click();
        const { invoice } = arrange('dine.order-state', { access_token });

        await page.goto(`/dashboard/transactions/history?invoice=${invoice}`);
        await page.getByRole('row').filter({ hasText: invoice }).getByRole('button', { name: /Konfirmasi|Bayar/ }).click();

        await guest.goto(`/dine-order/${access_token}`);
        await expect(guest.getByText('Selesai', { exact: true })).toBeVisible();
    });
});
