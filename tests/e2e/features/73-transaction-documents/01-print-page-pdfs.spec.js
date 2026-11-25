import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { expectPdf } from '../../support/reports.js';

const manager = roles.find((role) => role.key === 'manager');

// Each print mode of the transaction print page offers its own PDF; the link must download a real PDF
// named after the invoice. reports.sales: A1 has no customer, A2 has one.
test.describe('transaction documents: print page', () => {
    const pdfLink = (page, name) => page.getByRole('link', { name });

    test('the invoice and the 58mm receipt are PDFs named after the invoice', async ({ asRole, arrange }) => {
        test.slow(); // PDFs are rendered on request
        const { a1 } = arrange('reports.sales');
        const page = await asRole(manager);

        await page.goto(`/dashboard/transactions/${a1}/print`);
        await expect(page.getByText(a1).first()).toBeVisible();

        await expect(pdfLink(page, 'PDF Invoice')).toHaveAttribute('href', new RegExp(`/documents/transactions/${a1}/pdf/invoice$`));
        await expectPdf(page, await pdfLink(page, 'PDF Invoice').getAttribute('href'), `invoice-${a1}.pdf`);

        await page.getByRole('button', { name: 'Struk 58mm' }).click();
        await expect(pdfLink(page, 'PDF Struk 58mm')).toHaveAttribute('href', /\/pdf\/receipt\/58$/);
        await expectPdf(page, await pdfLink(page, 'PDF Struk 58mm').getAttribute('href'), `receipt-${a1}-58.pdf`);
    });

    test('the 80mm receipt is a PDF named after the invoice', async ({ asRole, arrange }) => {
        const { a1 } = arrange('reports.sales');
        const page = await asRole(manager);

        await page.goto(`/dashboard/transactions/${a1}/print`);
        await page.getByRole('button', { name: 'Struk 80mm' }).click();
        await expect(pdfLink(page, 'PDF Struk 80mm')).toHaveAttribute('href', /\/pdf\/receipt\/80$/);
        await expectPdf(page, await pdfLink(page, 'PDF Struk 80mm').getAttribute('href'), `receipt-${a1}-80.pdf`);
    });

    test('the shipping label of a sale with a customer is a PDF named after the invoice', async ({ asRole, arrange }) => {
        const { a2 } = arrange('reports.sales');
        const page = await asRole(manager);

        await page.goto(`/dashboard/transactions/${a2}/print`);
        await page.getByRole('button', { name: 'Resi' }).click();
        await expect(pdfLink(page, 'PDF Resi')).toHaveAttribute('href', new RegExp(`/documents/transactions/${a2}/pdf/shipping$`));
        await expectPdf(page, await pdfLink(page, 'PDF Resi').getAttribute('href'), `shipping-${a2}.pdf`);
    });

    test('the shipping label of a sale without a customer is a PDF named after the invoice', async ({ asRole, arrange }) => {
        const { a1 } = arrange('reports.sales');
        const page = await asRole(manager);

        await page.goto(`/dashboard/transactions/${a1}/print`);
        await page.getByRole('button', { name: 'Resi' }).click();
        await expectPdf(page, await pdfLink(page, 'PDF Resi').getAttribute('href'), `shipping-${a1}.pdf`);
    });

    test('the thermal preview is an HTML receipt of the sale', async ({ asRole, arrange }) => {
        const { a1 } = arrange('reports.sales');
        const page = await asRole(manager);

        const response = await page.request.get(`/dashboard/documents/transactions/${a1}/print/thermal`);
        expect(response.status()).toBe(200);
        expect(response.headers()['content-type']).toContain('text/html');
        expect(await response.text()).toContain(a1);
    });
});
