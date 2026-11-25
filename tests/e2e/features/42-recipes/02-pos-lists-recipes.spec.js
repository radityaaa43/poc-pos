import { test, expect } from '../../support/fixtures.js';
import { role, card, posSearch } from '../../support/inventory.js';

test.describe('42-recipes', () => {
    test('the POS shows a recipe with the portions the ingredients allow, and no raw materials', { tag: ['@REQ-001-AC-03'] }, async ({ asRole, arrange, uniq }) => {
        const prefix = uniq('Pos');
        // 100 g of beans at 18 g a portion is 5 portions; water and cups allow more.
        const { recipe, bean, regular } = arrange('inventory.recipe', { prefix, beans: 100, regular: 4 });
        arrange('open-shift', { cash: 100000 });
        const page = await asRole(role('cashier'));

        await page.goto('/dashboard/transactions');
        await posSearch(page, prefix);

        await expect(card(page, recipe)).toContainText('22.000');
        await expect(card(page, recipe)).toContainText('Sisa 5');
        await expect(card(page, regular)).toContainText('22.000');
        await expect(card(page, regular)).toContainText('Sisa 4');
        // The beans, water and cups carry the same prefix but are raw materials.
        await expect(page.getByRole('heading', { name: bean })).toHaveCount(0);
    });

    test('a recipe missing one portion of an ingredient is not listed', { tag: ['@REQ-001-AC-04'] }, async ({ asRole, arrange, uniq }) => {
        const prefix = uniq('Hbs');
        // 10 g of beans is less than the 18 g one portion needs.
        const { recipe, regular } = arrange('inventory.recipe', { prefix, beans: 10, regular: 4 });
        arrange('open-shift', { cash: 100000 });
        const page = await asRole(role('cashier'));

        await page.goto('/dashboard/transactions');
        await posSearch(page, prefix);

        await expect(card(page, regular)).toBeVisible();
        await expect(page.getByRole('heading', { name: recipe })).toHaveCount(0);
    });
});
