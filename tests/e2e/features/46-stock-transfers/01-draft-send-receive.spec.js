import { test, expect } from '../../support/fixtures.js';
import { role, acceptDialogs, stockCells, draftTransfer } from '../../support/inventory.js';

test.describe('46-stock-transfers', () => {
    // The extra warehouse must not outlive the step: other folders list every active warehouse.
    test.afterEach(({ arrange }) => arrange('inventory.transfer-done'));

    test('a manager moves 4 units: draft, in transit, completed', async ({ asRole, arrange, uniq }) => {
        const page = await asRole(role('manager'));
        const books = await asRole(role('manager'));
        acceptDialogs(page);
        const { product } = arrange('inventory.transfer', { prefix: uniq('Trf'), stock: 10 });
        const note = uniq('pindah');

        const document = await draftTransfer(page, product, 4, note);
        await expect(page.getByText(product)).toBeVisible();
        await expect(page.getByText(note)).toBeVisible();
        // A draft moves nothing.
        let [first, second] = await stockCells(books, product);
        await expect(first).toContainText('10');
        await expect(second).toContainText('0');

        // Sent: the source gives up the stock, the destination has not got it yet.
        await page.getByRole('button', { name: /Kirim Barang/ }).click();
        await expect(page.getByText('In Transit', { exact: true })).toBeVisible();
        [first, second] = await stockCells(books, product);
        await expect(first).toContainText('6');
        await expect(second).toContainText('0');

        // Received: the destination holds the 4 units and the transfer is closed.
        await page.getByRole('button', { name: /Terima Barang/ }).click();
        await expect(page.getByText('Selesai', { exact: true }).first()).toBeVisible();
        await expect(page.getByRole('button', { name: /Kirim Barang|Terima Barang|Batalkan/ })).toHaveCount(0);
        [first, second] = await stockCells(books, product);
        await expect(first).toContainText('6');
        await expect(second).toContainText('4');

        // Each leg is in the mutation history: out of the source, into the destination.
        await books.goto('/dashboard/stock-mutations');
        await books.locator('select').filter({ has: books.locator('option', { hasText: 'Semua Produk' }) }).selectOption({ label: product });
        await expect(books).toHaveURL(/product_id=/);
        await expect(books.getByRole('row').filter({ hasText: /Transfer ke XFER-B/ })).toContainText('out');
        await expect(books.getByRole('row').filter({ hasText: /Transfer dari WH-E2E/ })).toContainText('in');

        // The list shows the finished transfer.
        await page.goto('/dashboard/stock-transfers');
        await expect(page.getByRole('row').filter({ hasText: document })).toContainText('Selesai');
    });
});
