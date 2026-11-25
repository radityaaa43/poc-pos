import { test, expect } from '../../support/fixtures.js';
import { role, acceptDialogs, stockCells, draftTransfer } from '../../support/inventory.js';

test.describe('46-stock-transfers', () => {
    // Bug #123: the refusal is never shown to the user (Show.jsx ignores errors.transfer).
    test.fixme('sending more than the source holds is refused and tells the user why', async ({ asRole, arrange, uniq }) => {
        const page = await asRole(role('manager'));
        const books = await asRole(role('manager'));
        acceptDialogs(page);
        const { product } = arrange('inventory.transfer', { prefix: uniq('Lbh'), stock: 5 });

        await draftTransfer(page, product, 9, uniq('terlalu banyak'));
        await page.getByRole('button', { name: /Kirim Barang/ }).click();

        // Still a draft, nothing left the source...
        await expect(page.getByText('Draft', { exact: true })).toBeVisible();
        const [first, second] = await stockCells(books, product);
        await expect(first).toContainText('5');
        await expect(second).toContainText('0');
        // ...and the page says why (StockTransferService: "tidak mencukupi di gudang asal (tersedia: 5)").
        await expect(page.getByText(/tidak mencukupi di gudang asal/)).toBeVisible();
    });
});
