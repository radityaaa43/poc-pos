import { test, expect } from '../../support/fixtures.js';

test.describe('42-recipes', () => {
    test('the dine-in menu lists recipes and regular products but no raw materials', { tag: ['@REQ-001-AC-07'] }, async ({ page, arrange, uniq }) => {
        const prefix = uniq('Menu');
        const { recipe, bean, regular } = arrange('inventory.recipe', { prefix, beans: 500, regular: 4 });
        const { path } = arrange('inventory.dine-table', { name: uniq('Meja') });

        // The guest scans the table QR: no login.
        await page.goto(path);

        await expect(page.getByRole('heading', { name: recipe })).toBeVisible();
        await expect(page.getByRole('heading', { name: regular })).toBeVisible();
        await expect(page.getByRole('heading', { name: bean })).toHaveCount(0);
        await expect(page.getByRole('heading', { name: `${prefix} Air` })).toHaveCount(0);
    });
});
