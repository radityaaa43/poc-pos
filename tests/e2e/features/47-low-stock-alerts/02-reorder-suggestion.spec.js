import { test, expect } from '../../support/fixtures.js';
import { artisan } from '../../support/cli.js';
import { role } from '../../support/inventory.js';

test.describe('47-low-stock-alerts', () => {
    test('the daily reorder run drafts a purchase order for what is below its minimum', async ({ asRole, arrange, uniq }) => {
        const manager = await asRole(role('manager'));
        // Stock 2, minimum 5, maximum 20: the suggestion is the 18 units that fill it back up.
        const { product } = arrange('inventory.low-stock', { prefix: uniq('Reorder'), stock: 2, min: 5, max: 20 });

        const output = artisan(['reorder:generate']);
        const order = output.match(/Draft purchase order #(\d+) created for Gudang E2E/)?.[1];
        expect(order, output).toBeTruthy();

        await manager.goto(`/dashboard/purchase-orders/${order}`);
        await expect(manager.getByText('Draft', { exact: true }).first()).toBeVisible();
        await expect(manager.getByText('Auto-generated from restock suggestion')).toBeVisible();
        const line = manager.getByRole('row').filter({ hasText: product });
        await expect(line).toContainText('18');
    });

    test('the product form explains the minimum and maximum stock', async ({ asRole }) => {
        const page = await asRole(role('manager'));

        await page.goto('/dashboard/products/create');

        await expect(page.getByText(/saat stok menyentuh minimum, draft purchase order otomatis dibuat oleh sistem/)).toBeVisible();
    });
});
