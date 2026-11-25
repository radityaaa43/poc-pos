import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');

// #79: ticking "Bahan baku" on the Products form asks who buys it, like Master Data -> Bahan Baku.
test.describe('21-product-purchased-by', () => {
    test('raw-material-needs-buyer: ticking Bahan baku requires Dibeli Oleh, unticking hides and clears it', async ({ asRole, arrange }) => {
        const product = arrange('product');
        const page = await asRole(manager);
        const edit = `/dashboard/products/${product.product_id}/edit`;
        const bahanBaku = page.getByLabel('Bahan baku (tidak dijual di kasir)');
        const dibeliOleh = page.getByLabel('Dibeli Oleh');

        await page.goto(edit);
        await expect(dibeliOleh).toHaveCount(0);
        // The arranged product has no SKU, which the form requires on save.
        await page.getByPlaceholder('SKU unik').fill(product.barcode);

        // Saving a raw material with no buyer is rejected.
        await bahanBaku.check();
        await expect(dibeliOleh).toBeVisible();
        await page.getByRole('button', { name: 'Simpan Perubahan' }).click();
        await expect(page.getByText('Pilih siapa yang membeli bahan ini.')).toBeVisible();

        // Choosing one saves it, and the edit form shows it again.
        await dibeliOleh.selectOption('central');
        await page.getByRole('button', { name: 'Simpan Perubahan' }).click();
        await expect(page).toHaveURL(/dashboard\/products$/);
        await page.goto(edit);
        await expect(dibeliOleh).toHaveValue('central');

        // Unticking hides the select; saving stores no buyer for a plain product.
        await bahanBaku.uncheck();
        await expect(dibeliOleh).toHaveCount(0);
        await page.getByRole('button', { name: 'Simpan Perubahan' }).click();
        await expect(page).toHaveURL(/dashboard\/products$/);
        await page.goto(edit);
        await bahanBaku.check();
        await expect(dibeliOleh).toHaveValue('');
    });
});
