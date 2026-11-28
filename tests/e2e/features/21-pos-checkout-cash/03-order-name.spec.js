import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { sentWith, setUpPrintDevice } from '../../support/kitchen.js';
import { fillCart, readQueue } from '../../support/offline-pos.js';
import { addToCart, finish, openPos, rupiah, total } from '../../support/pos.js';
import { fakePrinter } from '../../support/webusb.js';

const cashier = roles.find((role) => role.key === 'cashier');

// A product the kitchen makes, so the sale reaches the display and a print device.
function menu(arrange, title) {
    const { product_id } = arrange('product', { title, stock: 5 });
    arrange('kitchen.flag', { product_id });
}

test.describe('POS checkout: order name', () => {
    test.describe.configure({ timeout: 240_000 });

    test('the typed name is on the receipt, the kitchen card and the kitchen ticket', { tag: ['@REQ-009-AC-20'] }, async ({ asRole, arrange, uniq }) => {
        const title = uniq('Nasi');
        menu(arrange, title);
        arrange('pos.open-shift', { cash: 100000 });
        const orderName = uniq('B'); // short enough for one double-size ticket line on 58 mm
        const kitchen = await asRole(cashier);
        await fakePrinter(kitchen);
        // The print device is named first: it prints only what reaches the kitchen after it was named.
        await setUpPrintDevice(kitchen, uniq('Dapur'));

        const page = await asRole(cashier);
        await openPos(page);
        await addToCart(page, title);
        // The add is answered before the cart re-renders: 'Uang pas' pressed too early pays Rp 0.
        await expect(total(page)).toHaveText(rupiah(10000));
        await page.getByLabel('Nama pesanan').fill(orderName);
        await page.getByRole('button', { name: 'Uang pas' }).click();
        const invoice = await finish(page);

        const line = page.getByText(`Nama pesanan: ${orderName}`, { exact: true });
        await expect(line).toBeVisible();
        await page.getByRole('button', { name: 'Struk 80mm' }).click();
        await expect(line).toBeVisible();
        await page.getByRole('button', { name: 'Struk 58mm' }).click();
        await expect(line).toBeVisible();

        const card = kitchen.locator('article', { hasText: invoice });
        await expect(card.getByRole('heading', { name: orderName, exact: true })).toBeVisible({ timeout: 15_000 });
        await expect.poll(() => sentWith(kitchen, orderName), { timeout: 15_000 }).toBeDefined();
    });

    test('a name typed offline reaches the kitchen after the sync, and the next sale starts blank', { tag: ['@REQ-009-AC-20'] }, async ({ asRole, arrange, uniq }) => {
        const title = uniq('Mie');
        const customer = uniq('Budi');
        menu(arrange, title);
        arrange('offline.customer', { name: customer });
        arrange('offline.clear-cart');
        arrange('open-shift', { cash: 100000 });
        const orderName = uniq('B');
        const page = await asRole(cashier);
        await fillCart(page, { title, customer });
        await page.getByLabel('Nama pesanan').fill(orderName);

        await page.context().setOffline(true);
        await page.getByRole('button', { name: 'Selesaikan Transaksi' }).click();
        await expect.poll(() => readQueue(page)).toHaveLength(1);
        const [queued] = await readQueue(page);
        expect(queued.data.order_name).toBe(orderName);
        await expect(page.getByLabel('Nama pesanan')).toHaveValue('');

        await page.context().setOffline(false);
        await expect(page.getByText('1 transaksi offline tersinkronisasi. Page akan dimuat ulang.')).toBeVisible();
        const { invoice } = arrange('offline.sync-state', { uuid: queued.client_uuid });
        const display = await asRole(cashier);
        await display.goto('/dashboard/kitchen');
        await expect(display.locator('article', { hasText: invoice }).getByRole('heading', { name: orderName, exact: true })).toBeVisible();
    });

    test('in English the field, the limit warning and the receipt line are in English', { tag: ['@REQ-009-AC-22'] }, async ({ asRole, arrange, uniq }) => {
        const title = uniq('Teh');
        arrange('product', { title, stock: 5 });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);
        // The browser's own choice, so the shared cashier account keeps its language.
        await page.addInitScript(() => localStorage.setItem('i18nextLng', 'en'));
        await openPos(page);
        await addToCart(page, title);

        const input = page.getByLabel('Order name');
        await expect(input).toHaveAttribute('placeholder', 'Name / buzzer number (optional)');
        await input.fill('Buzzer 1234567890123'); // exactly 20 characters
        await expect(page.getByText('20 characters maximum')).toBeVisible();
        await expect(total(page)).toHaveText(rupiah(10000));
        await page.getByRole('button', { name: 'Uang pas' }).click();
        await finish(page);

        await expect(page.getByText('Order name: Buzzer 1234567890123', { exact: true })).toBeVisible();
    });
});
