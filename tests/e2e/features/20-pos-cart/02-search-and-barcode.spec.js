import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { openPos, productCard, rupiah, scan, total } from '../../support/pos.js';

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('POS cart: search and barcode', () => {
    test.describe.configure({ timeout: 180_000 });

    test('the search box filters the grid by title and by barcode; nothing matching says so', async ({ asRole, arrange, uniq }) => {
        const wanted = arrange('product', { title: uniq('find-me') });
        const other = arrange('product', { title: uniq('other') });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);
        const search = page.getByPlaceholder(/Cari produk atau scan barcode/);

        await openPos(page);
        await expect(productCard(page, wanted.title)).toBeVisible();
        await expect(productCard(page, other.title)).toBeVisible();

        await search.fill(wanted.title);
        await expect(productCard(page, wanted.title)).toBeVisible();
        await expect(page.getByRole('heading', { name: other.title, level: 3 })).toHaveCount(0);

        // A barcode typed or scanned into the search box finds the product the same way.
        await search.fill(other.barcode);
        await expect(productCard(page, other.title)).toBeVisible();
        await expect(page.getByRole('heading', { name: wanted.title, level: 3 })).toHaveCount(0);

        await search.fill('no-such-product-zzz');
        await expect(page.getByText('Produk tidak ditemukan')).toBeVisible();

        // "/" jumps to the search box from anywhere on the page.
        await page.getByRole('heading', { name: 'Keranjang', level: 3 }).click();
        await page.keyboard.press('/');
        await expect(search).toBeFocused();
    });

    test('a scanned barcode adds the product; an unknown barcode is reported', async ({ asRole, arrange, uniq }) => {
        const product = arrange('product', { title: uniq('scanned') });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await openPos(page);
        await scan(page, product.barcode);
        await expect(page.getByText(`${product.title} ditambahkan (barcode)`)).toBeVisible();
        await expect(page.getByText('Rp 10.000 × 1')).toBeVisible();
        await expect(total(page)).toHaveText(rupiah(10000));

        // Scanning it again adds one more; the barcode match ignores letter case.
        await scan(page, product.barcode.toLowerCase());
        await expect(page.getByText('Rp 10.000 × 2')).toBeVisible();

        await scan(page, 'NOPE-123');
        await expect(page.getByText('Produk tidak ditemukan: NOPE-123')).toBeVisible();
        await expect(total(page)).toHaveText(rupiah(20000));
    });

    test('a product with no stock cannot be scanned', async ({ asRole, arrange, uniq }) => {
        const product = arrange('product', { title: uniq('scan-empty'), stock: 0 });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await openPos(page);
        await scan(page, product.barcode);
        await expect(page.getByText(`Produk tidak ditemukan: ${product.barcode}`)).toBeVisible();
        await expect(page.getByText('Keranjang kosong', { exact: true })).toBeVisible();
    });
});
