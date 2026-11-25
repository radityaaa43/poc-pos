import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { expectPdf } from '../../support/reports.js';

const role = (key) => roles.find((candidate) => candidate.key === key);

test.describe('finance documents: payable PDF', () => {
    test('the payable of a purchase order links to its PDF, named after the document number', async ({ asRole, arrange }) => {
        test.slow(); // PDFs are rendered on request
        const { payable_id, document_number } = arrange('reports.payable');
        const page = await asRole(role('manager'));

        await page.goto(`/dashboard/payables/${payable_id}`);
        await expect(page.getByText(document_number).first()).toBeVisible();
        await page.getByRole('button', { name: 'Preview / PDF' }).click();
        await expect(page.getByText('Preview Hutang')).toBeVisible();
        const link = page.getByRole('link', { name: 'PDF / Cetak' });
        await expect(link).toHaveAttribute('href', new RegExp(`/documents/payables/${payable_id}/pdf$`));
        await expectPdf(page, await link.getAttribute('href'), `hutang-${document_number}.pdf`);
    });

    test('the cashier may print it, stock-opname may not', async ({ asRole, arrange }) => {
        test.slow(); // PDFs are rendered on request
        const { payable_id, document_number } = arrange('reports.payable');

        const cashier = await asRole(role('cashier'));
        await expectPdf(cashier, `/dashboard/documents/payables/${payable_id}/pdf`, `hutang-${document_number}.pdf`);

        const opname = await asRole(role('stock-opname'));
        expect((await opname.request.get(`/dashboard/documents/payables/${payable_id}/pdf`)).status()).toBe(403);
    });

    test('a payable entered by hand (no purchase order) still prints', async ({ asRole, arrange }) => {
        const { manual_payable_id, manual_document_number } = arrange('reports.payable');
        const page = await asRole(role('manager'));

        await page.goto(`/dashboard/payables/${manual_payable_id}`);
        await page.getByRole('button', { name: 'Preview / PDF' }).click();
        const link = page.getByRole('link', { name: 'PDF / Cetak' });
        await expectPdf(page, await link.getAttribute('href'), `hutang-${manual_document_number}.pdf`);
    });
});
