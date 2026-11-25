import { test, expect } from '../../support/fixtures.js';
import { role, acceptDialogs, stockCells, draftTransfer } from '../../support/inventory.js';

test.describe('46-stock-transfers', () => {
    // The extra warehouse must not outlive the step: other folders list every active warehouse.
    test.afterEach(({ arrange }) => arrange('inventory.transfer-done'));

    test('cancelling a draft changes nothing', async ({ asRole, arrange, uniq }) => {
        const page = await asRole(role('manager'));
        const books = await asRole(role('manager'));
        acceptDialogs(page);
        const { product } = arrange('inventory.transfer', { prefix: uniq('Draf'), stock: 10 });

        await draftTransfer(page, product, 3, uniq('batal'));
        await page.getByRole('button', { name: 'Batalkan' }).click();

        await expect(page.getByText('Batal', { exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: /Kirim Barang|Batalkan/ })).toHaveCount(0);
        const [first, second] = await stockCells(books, product);
        await expect(first).toContainText('10');
        await expect(second).toContainText('0');
    });

    test('cancelling a transfer in transit gives the stock back to the source', async ({ asRole, arrange, uniq }) => {
        const page = await asRole(role('manager'));
        const books = await asRole(role('manager'));
        acceptDialogs(page);
        const { product } = arrange('inventory.transfer', { prefix: uniq('Jln'), stock: 10 });

        await draftTransfer(page, product, 7, uniq('batal'));
        await page.getByRole('button', { name: /Kirim Barang/ }).click();
        await expect(page.getByText('In Transit', { exact: true })).toBeVisible();
        let [first] = await stockCells(books, product);
        await expect(first).toContainText('3');

        await page.getByRole('button', { name: /Batalkan & Kembalikan/ }).click();
        await expect(page.getByText('Batal', { exact: true })).toBeVisible();
        [first] = await stockCells(books, product);
        await expect(first).toContainText('10');
    });

    test('the form offers no destination equal to the source and refuses an empty item list', async ({ asRole, arrange, uniq }) => {
        arrange('inventory.transfer', { prefix: uniq('Form') });
        const page = await asRole(role('manager'));

        await page.goto('/dashboard/stock-transfers/create');
        await page.locator('select').nth(0).selectOption({ label: 'WH-E2E — Gudang E2E' });
        await expect(page.locator('select').nth(1).locator('option', { hasText: 'WH-E2E — Gudang E2E' })).toHaveCount(0);
        await expect(page.locator('select').nth(1).locator('option', { hasText: 'XFER-B' })).toHaveCount(1);
        await page.getByRole('button', { name: 'Simpan Draft' }).click();
        await expect(page.getByText('Tambahkan minimal satu item.')).toBeVisible();
        await expect(page).toHaveURL(/\/stock-transfers\/create$/);
    });

    test('only roles with transfer access can open transfers', async ({ asRole }) => {
        for (const key of ['cashier', 'stock-opname']) {
            const page = await asRole(role(key));
            const response = await page.goto('/dashboard/stock-transfers');
            expect(response.status(), key).toBe(403);
        }
    });
});
