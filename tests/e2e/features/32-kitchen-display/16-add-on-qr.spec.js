import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');

test.describe('32-kitchen-display', () => {
    test.describe.configure({ timeout: 240_000 });

    test('a guest orders again with Tambah pesanan: the accepted add-on is linked and joins the original card under Tambahan', { tag: ['@REQ-003-AC-21'] }, async ({ page, asRole, arrange, uniq }) => {
        const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const [first, second] = [uniq('Soto'), uniq('Wedang')].map((title) => {
            const { product_id } = arrange('product', { title, stock: 5 });
            arrange('kitchen.flag', { product_id });
            return { title, product_id };
        });
        const original = arrange('dine.order', { token, product_id: first.product_id, qty: 1 });
        arrange('open-shift', { cashier: manager.email, cash: 100000 });
        const staff = await asRole(manager);
        const accept = async () => {
            await staff.goto('/dashboard/dine-orders');
            const waiting = staff.locator('div.rounded-2xl', { hasText: table_name }).filter({ has: staff.getByRole('button', { name: 'Terima' }) });
            staff.once('dialog', (confirm) => confirm.accept());
            await waiting.getByRole('button', { name: 'Terima' }).click();
            await expect(waiting).toHaveCount(0);
        };
        await accept();
        // The guest (signed in to nothing) orders again from the status page of the accepted order.
        await page.goto(`/dine-order/${original.access_token}`);
        await page.getByRole('link', { name: 'Tambah pesanan' }).click();
        await page.locator('div.rounded-xl', { has: page.getByRole('heading', { name: second.title }) }).getByRole('button', { name: '+ Tambah' }).click();
        await page.getByRole('button', { name: /1 item/ }).click();
        await page.getByRole('dialog').getByRole('button', { name: 'Pesan', exact: true }).click();
        await page.waitForURL((url) => url.pathname.startsWith('/dine-order/') && !url.pathname.endsWith(original.access_token));
        const addOnToken = new URL(page.url()).pathname.split('/').pop();
        await accept();
        // Pay at the counter: each sale reaches the kitchen once it is paid.
        const [originalInvoice, addOnInvoice] = [[original.access_token, first.product_id], [addOnToken, second.product_id]].map(([access_token, product_id]) => {
            const { invoice } = arrange('dine.order-state', { access_token, product_id });
            arrange('kitchen.set-status', { invoice, status: 'paid' });
            return invoice;
        });
        await staff.goto(`/dashboard/transactions/${addOnInvoice}/print`);
        await expect(staff.getByText(`Tambahan untuk ${originalInvoice}`)).toBeVisible({ timeout: 45_000 });
        await staff.goto('/dashboard/kitchen');
        // One card, the original's: the add-on's items join it under a Tambahan heading (#314).
        const cards = staff.locator('article', { hasText: table_name });
        await expect(cards).toHaveCount(1);
        await expect(cards).toContainText(first.title);
        await expect(cards).toContainText(second.title);
        await expect(cards.getByRole('heading', { name: 'Tambahan' })).toBeVisible();
    });
});
