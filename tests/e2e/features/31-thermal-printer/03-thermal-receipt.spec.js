import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { fillCart } from '../../support/offline-pos.js';
import { fakePrinter, sentToPrinter, asText, INIT, CUT, DRAWER_KICK } from '../../support/webusb.js';

const cashier = roles.find((role) => role.key === 'cashier');

// Sells one product for cash and lands on its receipt page, as the cashier does after checkout.
async function receiptAfterSale({ page, arrange, uniq }) {
    const title = uniq('Kopi');
    const customer = uniq('Budi');
    arrange('product', { title, stock: 10 });
    arrange('offline.customer', { name: customer });
    arrange('offline.clear-cart');
    arrange('open-shift', { cash: 100000 });
    await fillCart(page, { title, customer });
    await page.getByRole('button', { name: 'Selesaikan Transaksi' }).click();
    await expect(page).toHaveURL(/\/dashboard\/transactions\/[^/]+\/print$/);
    const invoice = new URL(page.url()).pathname.split('/').at(-2);

    return { title, invoice };
}

test.describe('thermal printer: receipt page', () => {
    test('the Thermal button sends the receipt as ESC/POS bytes, then opens the cash drawer', async ({ asRole, arrange, uniq }) => {
        test.setTimeout(180_000);
        const page = await asRole(cashier);
        await fakePrinter(page, { granted: true });
        const { title, invoice } = await receiptAfterSale({ page, arrange, uniq });

        await page.getByRole('button', { name: 'Struk 58mm' }).click();
        await page.getByRole('button', { name: 'Thermal' }).click();

        await expect.poll(async () => (await sentToPrinter(page)).length).toBe(2);
        const [receipt, kick] = await sentToPrinter(page);
        expect(receipt.slice(0, 2)).toEqual(INIT);
        expect(receipt.slice(-4)).toEqual(CUT);
        const text = asText(receipt);
        expect(text).toContain(`#${invoice}`);
        expect(text).toContain(`1x ${title}`.slice(0, 32));
        expect(text).toContain('TOTAL');
        expect(text).toContain(`\n${'-'.repeat(32)}\n`);
        expect(kick).toEqual(DRAWER_KICK);
    });

    test('without WebUSB the Thermal button falls back to the browser thermal preview', async ({ asRole, arrange, uniq }) => {
        test.setTimeout(180_000);
        const page = await asRole(cashier);
        await page.addInitScript(() => Object.defineProperty(navigator, 'usb', { configurable: true, value: undefined }));
        const { invoice } = await receiptAfterSale({ page, arrange, uniq });

        const popup = page.waitForEvent('popup');
        await page.getByRole('button', { name: 'Thermal' }).click();

        await expect((await popup).getByText(invoice).first()).toBeVisible();
    });

    test('the Thermal button tells the cashier the receipt was printed', async ({ asRole, arrange, uniq }) => {
        test.setTimeout(180_000);
        const page = await asRole(cashier);
        await fakePrinter(page, { granted: true });
        await receiptAfterSale({ page, arrange, uniq });

        await page.getByRole('button', { name: 'Thermal' }).click();

        await expect(page.getByText('Struk tercetak langsung ke printer thermal.')).toBeVisible();
    });
});
