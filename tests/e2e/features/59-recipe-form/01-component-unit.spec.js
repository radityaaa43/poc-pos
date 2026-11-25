import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const expect = baseExpect.configure({ timeout: 45_000 });

const manager = roles.find((role) => role.key === 'manager');

test.describe('59-recipe-form', () => {
    test.describe.configure({ timeout: 240_000 }); // a loaded runner needs more than the default 30 s
    test.use({ actionTimeout: 45_000 });

    test('component-unit: raw materials come first in the picker and the dose shows the stock unit', {
        tag: ['@REQ-002-AC-18'],
    }, async ({ asRole, arrange, uniq }) => {
        // The plain product sorts before the material by name, so only the grouping can put the material first.
        const material = uniq('Zz Biji kopi');
        arrange('purchasing.material', { title: material });
        const plain = uniq('Aa Gelas');
        arrange('product', { title: plain });
        const page = await asRole(manager);

        await page.goto('/dashboard/products/create');
        await page.getByText('Produk Komposit (resep / paket)').click();
        await page.getByRole('button', { name: '+ Tambah Komponen' }).click();
        await page.getByRole('button', { name: 'Pilih produk komponen' }).click();

        const labels = await page.getByRole('option').allTextContents();
        const isMaterial = labels.map((label) => label.includes('(bahan baku)'));
        expect(isMaterial[0]).toBe(true);
        // Every raw material is listed before the first other product.
        expect(isMaterial.indexOf(false)).toBeGreaterThan(-1);
        expect(isMaterial.lastIndexOf(true)).toBeLessThan(isMaterial.indexOf(false));
        expect(labels.findIndex((label) => label.includes(material))).toBeLessThan(labels.findIndex((label) => label.includes(plain)));

        // Picking the material shows its dose in the stock unit: 18 g.
        await page.getByRole('option', { name: `${material} (bahan baku)` }).click();
        await page.getByPlaceholder('Qty').fill('18');
        await expect(page.getByPlaceholder('Qty')).toHaveValue('18');
        await expect(page.getByText('g', { exact: true })).toBeVisible();

        // A plain product has no unit of its own and counts in pcs.
        await page.getByRole('button', { name: '+ Tambah Komponen' }).click();
        await page.getByRole('button', { name: 'Pilih produk komponen' }).click();
        await page.getByRole('option', { name: plain }).click();
        await expect(page.getByText('pcs', { exact: true })).toBeVisible();
    });
});
