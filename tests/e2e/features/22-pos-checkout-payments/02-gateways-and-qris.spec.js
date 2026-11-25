import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { addToCart, chooseCustomer, finish, openPos } from '../../support/pos.js';

const cashier = roles.find((role) => role.key === 'cashier');
// Seeded by E2eSeeder; the faked gateway never checks it, the webhook does.
const XENDIT_CALLBACK_TOKEN = 'e2e-xendit-callback-token';

test.describe('POS checkout: payment gateways and QRIS', () => {
    test.describe.configure({ timeout: 120_000 });

    for (const gateway of [
        { label: 'Midtrans', link: /pay\.e2e\.test\/midtrans\// },
        { label: 'Xendit', link: /pay\.e2e\.test\/xendit\// },
    ]) {
        test(`${gateway.label} creates a payment link and leaves the sale awaiting payment`, async ({ asRole, arrange, uniq }) => {
            const product = arrange('product', { title: uniq(gateway.label.toLowerCase()), stock: 10 });
            const customer = arrange('pos.customer', { name: uniq('Rina') });
            arrange('pos.open-shift', { cash: 100000 });
            const page = await asRole(cashier);

            await openPos(page);
            await addToCart(page, product.title);
            await chooseCustomer(page, customer.name);
            await page.getByRole('button', { name: gateway.label, exact: true }).click();
            await finish(page);

            // The faked gateway answers with a hosted-payment URL, shown to the cashier as "Pembayaran".
            await expect(page.getByText('Menunggu', { exact: true })).toBeVisible();
            await expect(page.getByRole('link', { name: 'Pembayaran' })).toHaveAttribute('href', gateway.link);
        });
    }

    test('a Xendit sale turns paid when the gateway reports it', async ({ asRole, arrange, uniq }) => {
        const product = arrange('product', { title: uniq('xendit-paid'), stock: 10 });
        const customer = arrange('pos.customer', { name: uniq('Tono') });
        arrange('pos.open-shift', { cash: 100000 });
        const page = await asRole(cashier);

        await openPos(page);
        await addToCart(page, product.title);
        await chooseCustomer(page, customer.name);
        await page.getByRole('button', { name: 'Xendit', exact: true }).click();
        const invoice = await finish(page);
        await expect(page.getByText('Menunggu', { exact: true })).toBeVisible();

        // Xendit calls the callback with the shared token; the receipt shows paid on the next load.
        const webhook = await page.request.post('/api/webhooks/xendit', {
            headers: { 'X-CALLBACK-TOKEN': XENDIT_CALLBACK_TOKEN },
            data: { external_id: invoice, status: 'PAID', id: `e2e-pay-${invoice}` },
        });
        expect(webhook.ok()).toBeTruthy();
        await page.reload();
        await expect(page.getByText('Lunas', { exact: true })).toBeVisible();
    });

    test('a QRIS sale shows the QR code and flips to paid on its own once the gateway reports it', async ({ asRole, arrange }) => {
        const product = arrange('product', { stock: 10 });
        arrange('pos.open-shift', { cash: 100000 });
        const { invoice } = arrange('pos.qris-sale', { product_id: product.product_id, qty: 1 });
        const page = await asRole(cashier);

        await page.goto(`/dashboard/transactions/${invoice}/print`);
        await expect(page.getByText('Scan QRIS untuk membayar')).toBeVisible();
        await expect(page.getByText('Menunggu pembayaran…')).toBeVisible();
        const qr = page.getByRole('img', { name: 'QRIS' });
        await expect(qr).toBeVisible();
        const image = await page.request.get(await qr.getAttribute('src'));
        expect(image.headers()['content-type']).toContain('image/svg+xml');

        // The page polls the status every 4 s; once the gateway reports payment it says so without a reload.
        const webhook = await page.request.post('/api/webhooks/xendit', {
            headers: { 'X-CALLBACK-TOKEN': XENDIT_CALLBACK_TOKEN },
            data: { external_id: invoice, status: 'PAID', id: `e2e-pay-${invoice}` },
        });
        expect(webhook.ok()).toBeTruthy();
        // (The 'Pembayaran QRIS diterima!' toast is gone after two seconds; the settled receipt is what stays.)
        await expect(page.getByText('Lunas', { exact: true })).toBeVisible({ timeout: 30_000 });
        await expect(page.getByText('Scan QRIS untuk membayar')).toHaveCount(0);
    });
});
