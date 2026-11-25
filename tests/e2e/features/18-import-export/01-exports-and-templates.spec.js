import { readFile } from 'node:fs/promises';
import { test, expect as baseExpect } from '../../support/fixtures.js';
import { signIn } from '../../support/platform.js';
import { xlsxText } from '../../support/xlsx.js';

// Pages here are slow to answer on a shared machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 45_000 });
test.use({ actionTimeout: 60_000, navigationTimeout: 60_000 });

const PRODUCT_HEADINGS = ['barcode', 'sku', 'nama', 'deskripsi', 'kategori', 'harga_beli', 'harga_jual', 'stok', 'min_stok', 'max_stok', 'tipe_pajak', 'tarif_pajak'];

// Clicks the Export link on the page and returns the text inside the downloaded workbook.
async function exportText(page, linkPath) {
    const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.locator(`a[href$="${linkPath}"]`).click(),
    ]);
    expect(download.suggestedFilename()).toMatch(/\.xlsx$/);

    return xlsxText(await readFile(await download.path()));
}

test.describe('export and import templates', () => {
    test.setTimeout(240_000);

    test('the product and customer exports list the records on the page', async ({ page, arrange, uniq }) => {
        const { email } = arrange('platform.user');
        const customerName = uniq('Pelanggan Ekspor');
        const { title } = arrange('product', { title: uniq('Produk Ekspor') });
        arrange('platform.customer', { name: customerName });

        try {
            await signIn(page, email);

            await page.goto('/dashboard/products');
            const products = await exportText(page, '/export/products');
            for (const heading of ['Barcode', 'Nama', 'Harga Jual']) expect(products).toContain(heading);
            expect(products).toContain(title);

            await page.goto('/dashboard/customers');
            const customers = await exportText(page, '/export/customers');
            expect(customers).toContain(customerName);
            expect(customers).toContain('Jl. Impor 1');
        } finally {
            arrange('platform.drop-customer', { name: customerName });
        }
    });

    test('the templates carry the headers the importer expects', async ({ page, arrange }) => {
        const { email } = arrange('platform.user');
        await signIn(page, email);

        const products = await page.request.get('/dashboard/import/template/products');
        expect(products.ok()).toBe(true);
        const productHeadings = xlsxText(await products.body());
        for (const heading of PRODUCT_HEADINGS) expect(productHeadings).toContain(heading);

        const customers = await page.request.get('/dashboard/import/template/customers');
        const customerHeadings = xlsxText(await customers.body());
        for (const heading of ['nama', 'telepon', 'alamat']) expect(customerHeadings).toContain(heading);

        expect((await page.request.get('/dashboard/import/template/unknown')).status()).toBe(404);
    });
});
