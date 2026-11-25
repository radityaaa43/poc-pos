import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');

// #73: a recipe has no manual buy price; its HPP per portion comes from the components' average cost
// in the active outlet. The arranged components average 300 and 250 (buy price 5000), so Rp 1.350
// (2 x 300 + 3 x 250) can only come from the warehouse averages.
test.describe('09-recipe-cost', () => {
    test('edit-form: manager sees the recipe HPP for the outlet and no Harga Beli', async ({ asRole, arrange }) => {
        const recipe = arrange('recipe');
        const page = await asRole(manager);

        await page.goto(`/dashboard/products/${recipe.product_id}/edit`);

        await expect(page.getByText(`HPP per porsi (${recipe.outlet}):`)).toContainText('Rp 1.350');
        await expect(page.getByText('Harga Beli', { exact: true })).toHaveCount(0);
        await expect(page.getByText('Estimasi Profit per Item')).toBeVisible();
    });

    test('edit-form: a plain product still asks for Harga Beli', async ({ asRole, arrange }) => {
        const product = arrange('product');
        const page = await asRole(manager);

        await page.goto(`/dashboard/products/${product.product_id}/edit`);

        await expect(page.getByText('Harga Beli', { exact: true }).first()).toBeVisible();
    });

    test('products list: the recipe shows the same cost, not its stale buy price', async ({ asRole, arrange }) => {
        const recipe = arrange('recipe');
        const page = await asRole(manager);

        await page.goto(`/dashboard/products?search=${encodeURIComponent(recipe.title)}`);

        await expect(page.getByText('Modal: Rp 1.350').first()).toBeVisible();
        await expect(page.getByText('6.200')).toHaveCount(0);
    });
});
