import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { rupiah } from '../../support/pos.js';

const cashier = roles.find((role) => role.key === 'cashier');

// One paid sale of 2 x 10.000 to a customer, rung up by the cashier.
function arrangeSale(arrange, uniq) {
    const product = arrange('product', { title: uniq('receipt'), stock: 10 });
    const customer = arrange('pos.customer', { name: uniq('Pak Joko') });
    arrange('pos.open-shift', { cash: 100000 });
    const { invoice } = arrange('pos.sale', { product_id: product.product_id, qty: 2, customer_id: customer.customer_id });

    return { product, customer, invoice };
}

test.describe('POS receipt: formats, PDF and share link', () => {
    test.describe.configure({ timeout: 120_000 });

    test('the receipt shows as invoice, 80 mm and 58 mm strips and shipping label, each with its PDF', async ({ asRole, arrange, uniq }) => {
        const { product, customer, invoice } = arrangeSale(arrange, uniq);
        const page = await asRole(cashier);

        await page.goto(`/dashboard/transactions/${invoice}/print`);

        // Invoice: store, customer, cashier, the lines and the total.
        await expect(page.getByText('INVOICE', { exact: true })).toBeVisible();
        await expect(page.getByText(invoice, { exact: true }).first()).toBeVisible();
        await expect(page.getByText(customer.name)).toBeVisible();
        await expect(page.getByText('E2E Cashier')).toBeVisible();
        const line = page.getByRole('row').filter({ hasText: product.title });
        await expect(line).toContainText(rupiah(10000));
        await expect(line).toContainText(rupiah(20000));
        await expect(page.getByText('Terima kasih telah berbelanja')).toBeVisible();
        await expect(page.getByRole('link', { name: 'PDF Invoice' })).toHaveAttribute('href', new RegExp(`/documents/transactions/${invoice}/pdf/invoice$`));

        // The PDF is really served.
        const pdf = await page.request.get(await page.getByRole('link', { name: 'PDF Invoice' }).getAttribute('href'));
        expect(pdf.ok()).toBeTruthy();
        expect(pdf.headers()['content-type']).toContain('application/pdf');

        // Thermal strips, then the shipping label, each with its own PDF link.
        await page.getByRole('button', { name: 'Struk 80mm' }).click();
        await expect(page.getByText(product.title).first()).toBeVisible();
        await expect(page.getByRole('link', { name: 'PDF Struk 80mm' })).toHaveAttribute('href', /\/pdf\/receipt\/80$/);
        await expect(page.getByRole('link', { name: 'PDF Invoice' })).toHaveCount(0);

        await page.getByRole('button', { name: 'Struk 58mm' }).click();
        await expect(page.getByText(product.title).first()).toBeVisible();
        await expect(page.getByRole('link', { name: 'PDF Struk 58mm' })).toHaveAttribute('href', /\/pdf\/receipt\/58$/);

        await page.getByRole('button', { name: 'Resi' }).click();
        await expect(page.getByRole('link', { name: 'PDF Resi' })).toHaveAttribute('href', new RegExp(`/documents/transactions/${invoice}/pdf/shipping$`));
        await expect(page.getByText(customer.name).first()).toBeVisible();
    });

    test('Share copies the invoice link with its access token, and the invoice is closed without it', async ({ asRole, arrange, uniq, page: guest }) => {
        const { invoice } = arrangeSale(arrange, uniq);
        const page = await asRole(cashier);
        await page.context().grantPermissions(['clipboard-read', 'clipboard-write']);

        // Share ends with a native alert, which blocks the click until it is handled.
        let message;
        page.once('dialog', async (dialog) => {
            message = dialog.message();
            await dialog.accept();
        });
        await page.goto('/dashboard/transactions/' + invoice + '/print');
        await page.getByRole('button', { name: 'Share' }).click();
        await expect.poll(() => message).toBe('Link invoice disalin');

        const link = await page.evaluate(() => navigator.clipboard.readText());
        expect(link).toContain('/portal/transactions/' + invoice + '?token=');

        // Without the token a guest gets nothing.
        const noToken = await guest.request.get('/portal/transactions/' + invoice);
        expect(noToken.status()).toBeGreaterThanOrEqual(400);
    });

    // Bug #150: the copied link has the site origin twice, so it 404s.
    test.fixme('a guest can open the link Share copies', async ({ asRole, arrange, uniq, page: guest }) => {
        const { product, invoice } = arrangeSale(arrange, uniq);
        const page = await asRole(cashier);
        await page.context().grantPermissions(['clipboard-read', 'clipboard-write']);
        page.once('dialog', (dialog) => dialog.accept());
        await page.goto('/dashboard/transactions/' + invoice + '/print');
        await page.getByRole('button', { name: 'Share' }).click();
        await expect.poll(() => page.evaluate(() => navigator.clipboard.readText())).toContain('/portal/');

        await guest.goto(await page.evaluate(() => navigator.clipboard.readText()));
        await expect(guest.getByText(invoice).first()).toBeVisible();
        await expect(guest.getByText(product.title).first()).toBeVisible();
    });

    test('the history offers the invoice over WhatsApp with a share link to this sale', async ({ asRole, arrange, uniq }) => {
        const { invoice } = arrangeSale(arrange, uniq);
        const page = await asRole(cashier);

        await page.goto('/dashboard/transactions/history');
        const row = page.getByRole('row').filter({ hasText: invoice });
        const href = await row.getByRole('link', { name: 'Bagikan ke WhatsApp' }).getAttribute('href');
        expect(href).toMatch(/^https:\/\/wa\.me\/\?text=/);
        expect(decodeURIComponent(href.split('text=')[1])).toContain(`Invoice ${invoice}: `);
        expect(decodeURIComponent(href)).toContain(`/share/transactions/${invoice}`);
    });

    // Bug #148: the history link drops the access token, so the recipient gets a 404.
    test.fixme('the history\'s WhatsApp link opens the invoice for a guest', async ({ asRole, arrange, uniq, page: guest }) => {
        const { invoice } = arrangeSale(arrange, uniq);
        const page = await asRole(cashier);

        await page.goto('/dashboard/transactions/history');
        const href = await page.getByRole('row').filter({ hasText: invoice }).getByRole('link', { name: 'Bagikan ke WhatsApp' }).getAttribute('href');
        const shared = decodeURIComponent(href.split('text=')[1]).split(': ')[1];

        const response = await guest.request.get(shared);
        expect(response.ok()).toBeTruthy();
        expect(response.headers()['content-type']).toContain('application/pdf');
    });
});
