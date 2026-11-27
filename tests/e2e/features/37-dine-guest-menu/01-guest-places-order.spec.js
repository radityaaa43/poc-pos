import { test, expect } from '../../support/fixtures.js';

// The guest is not logged in: the default `page` fixture has no session.
test.describe('37-dine-guest-menu', () => {
    test('a guest scans the table link, orders without logging in and sees the order waiting', async ({ page, arrange, uniq }) => {
        const { table_name, area_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
        const title = uniq('Kopi');
        const { product_id } = arrange('product', { title, stock: 5 });
        const note = uniq('tanpa gula');

        await page.goto(`/dine/${token}`);
        await expect(page.getByText(`Meja ${table_name} · ${area_name}`)).toBeVisible();
        const card = page.locator('div.rounded-xl', { has: page.getByRole('heading', { name: title }) });
        await expect(card).toContainText(/Rp\s*10\.000/);

        // Add twice: "+ Tambah", then the "+" stepper.
        await card.getByRole('button', { name: '+ Tambah' }).click();
        await card.getByRole('button').nth(1).click();
        await expect(card.getByText('2', { exact: true })).toBeVisible();

        await page.getByRole('button', { name: /2 item/ }).click();
        const cart = page.getByRole('dialog');
        await expect(cart.getByText('Pesanan Anda')).toBeVisible();
        await expect(cart.getByText(title)).toBeVisible();
        await expect(cart.getByText('Total').locator('..')).toContainText(/Rp\s*20\.000/);
        await cart.getByPlaceholder('Catatan pesanan (opsional)').fill(note);
        await cart.getByRole('button', { name: 'Pesan', exact: true }).click();

        // Status page for this order.
        await expect(page).toHaveURL(/\/dine-order\/[0-9a-f-]{36}$/);
        await expect(page.getByText('Menunggu Konfirmasi', { exact: true })).toBeVisible();
        await expect(page.getByText(`Meja ${table_name}`)).toBeVisible();
        await expect(page.getByText(title)).toBeVisible();
        await expect(page.getByText(note)).toBeVisible();
        await expect(page.getByText('Total').locator('..')).toContainText(/Rp\s*20\.000/);

        // Stored as submitted; stock is only taken when staff accept (docs/features/dine-in.md).
        const accessToken = new URL(page.url()).pathname.split('/').pop();
        expect(arrange('dine.order-state', { access_token: accessToken, product_id })).toMatchObject({
            status: 'submitted', subtotal: 20000, item_count: 2, notes: note, product_stock: 5, warehouse_stock: 5,
        });
    });
});
