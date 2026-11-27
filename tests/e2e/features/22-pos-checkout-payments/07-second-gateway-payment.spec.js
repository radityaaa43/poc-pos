import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const cashier = roles.find((role) => role.key === 'cashier');
// Seeded by E2eSeeder; the webhook checks it.
const XENDIT_CALLBACK_TOKEN = 'e2e-xendit-callback-token';
const NOTICE = 'Pembayaran gateway diterima tapi tidak dibukukan — perlu refund manual';

test.describe('POS checkout: second gateway payment of a paid sale', () => {
    test.describe.configure({ timeout: 240_000 });

    test('another reference marks the paid sale for a manual refund, a redelivery does not', async ({ asRole, arrange }) => {
        const product = arrange('product', { stock: 10 });
        arrange('pos.open-shift', { cash: 100000 });
        const { invoice } = arrange('pos.qris-sale', { product_id: product.product_id, qty: 1 });
        const page = await asRole(cashier);
        const settle = async (id) => {
            const webhook = await page.request.post('/api/webhooks/xendit', {
                headers: { 'X-CALLBACK-TOKEN': XENDIT_CALLBACK_TOKEN },
                data: { external_id: invoice, status: 'PAID', id },
            });
            expect(webhook.ok()).toBeTruthy();
        };

        await settle(`e2e-pay-${invoice}`);
        await settle(`e2e-pay-${invoice}`); // redelivered

        await page.goto(`/dashboard/transactions/${invoice}/print`);
        await expect(page.getByText('Lunas', { exact: true })).toBeVisible();
        await expect(page.getByText(NOTICE)).toHaveCount(0);

        await settle(`e2e-again-${invoice}`); // the customer paid the same QR twice

        await page.reload();
        await expect(page.getByText(NOTICE)).toBeVisible();
        await expect(page.getByText('Lunas', { exact: true })).toBeVisible();
    });
});
