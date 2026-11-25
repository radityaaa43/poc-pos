import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { addToCart, cardButton, increment, openPos, productCard, rupiah, total } from '../../support/pos.js';

const cashier = roles.find((role) => role.key === 'cashier');

test.describe('POS cart: unit switch', () => {
    test.describe.configure({ timeout: 180_000 });

    test('a box button on the card switches the price shown and adds the product by the box', async ({ asRole, arrange, uniq }) => {
        // 30 pcs in the warehouse; 1 box = 12 pcs at 108.000 (a pcs is 10.000).
        const product = arrange('pos.unit-product', { title: uniq('unit'), stock: 30, factor: 12 });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await openPos(page);
        const card = productCard(page, product.title);
        await expect(card.getByText('Rp 10.000')).toBeVisible();

        await card.getByRole('button', { name: 'BOX', exact: true }).click();
        await expect(card.getByText('Rp 108.000')).toBeVisible();
        await expect(card.getByText('/BOX')).toBeVisible();

        // Back to the base unit and price.
        await card.getByRole('button', { name: 'Dasar', exact: true }).click();
        await expect(card.getByText('Rp 10.000')).toBeVisible();

        await addToCart(page, product.title, { unit: 'BOX' });
        await expect(page.getByText('1 item')).toBeVisible();
    });

    test('a box is priced with the box price in the cart', async ({ asRole, arrange, uniq }) => {
        const product = arrange('pos.unit-product', { title: uniq('unit-price'), stock: 30, factor: 12 });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await openPos(page);
        await addToCart(page, product.title, { unit: 'BOX' });
        await expect(page.getByText('Rp 108.000 × 1')).toBeVisible();
        await expect(total(page)).toHaveText(rupiah(108000));
    });

    test('adding pieces after a box makes a separate cart row, each at its own price', async ({ asRole, arrange, uniq }) => {
        const product = arrange('pos.unit-product', { title: uniq('unit-rows'), stock: 30, factor: 12 });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await openPos(page);
        await addToCart(page, product.title, { unit: 'BOX' });
        await productCard(page, product.title).getByRole('button', { name: 'Dasar', exact: true }).click();
        await addToCart(page, product.title);

        await expect(page.getByText('Rp 108.000 × 1')).toBeVisible();
        await expect(page.getByText('Rp 10.000 × 1')).toBeVisible();
        await expect(total(page)).toHaveText(rupiah(118000));
    });

    test('two boxes (24 pcs) fit in 30 pcs of stock', async ({ asRole, arrange, uniq }) => {
        const product = arrange('pos.unit-product', { title: uniq('unit-stock'), stock: 30, factor: 12 });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await openPos(page);
        await addToCart(page, product.title, { unit: 'BOX' });
        await increment(page);
        await expect(page.getByText(/× 2$/)).toBeVisible();
    });

    test('a third box (36 pcs) does not fit in 30 pcs: the cart says why and keeps 2', async ({ asRole, arrange, uniq }) => {
        const product = arrange('pos.unit-product', { title: uniq('unit-limit'), stock: 30, factor: 12 });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await openPos(page);
        await addToCart(page, product.title, { unit: 'BOX' });
        await increment(page);
        await expect(page.getByText(/× 2$/)).toBeVisible();
        await page.waitForLoadState('networkidle');
        const refused = expect(page.getByText('Stok tidak mencukupi. Tersedia: 30')).toBeVisible();
        await increment(page);
        await refused;
        await expect(page.getByText(/× 2$/)).toBeVisible();
    });

    test('a box does not fit when the warehouse holds fewer pieces than the box', async ({ asRole, arrange, uniq }) => {
        const product = arrange('pos.unit-product', { title: uniq('unit-short'), stock: 5, factor: 12 });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await openPos(page);
        await productCard(page, product.title).getByRole('button', { name: 'BOX', exact: true }).click();
        const added = page.waitForResponse((response) => response.url().endsWith('/addToCart'));
        await cardButton(page, product.title).click();
        await added;
        // The box (12 pcs) is more than the 5 on the shelf: nothing is added.
        await expect(page.getByText('Keranjang kosong', { exact: true })).toBeVisible();
    });

    test('a refused add says why and does not claim success', async ({ asRole, arrange, uniq }) => {
        const product = arrange('pos.unit-product', { title: uniq('unit-refused'), stock: 5, factor: 12 });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await openPos(page);
        await productCard(page, product.title).getByRole('button', { name: 'BOX', exact: true }).click();
        await cardButton(page, product.title).click();
        await expect(page.getByText('Stok tidak mencukupi.')).toBeVisible();
        await expect(page.getByText('ditambahkan')).toHaveCount(0);
        await expect(page.getByText('Keranjang kosong', { exact: true })).toBeVisible();
    });
});
