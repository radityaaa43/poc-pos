import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { expectPdf } from '../../support/reports.js';

const role = (key) => roles.find((candidate) => candidate.key === key);

test.describe('finance documents: receivable PDF', () => {
    test('the receivable page links to its PDF, named after the invoice', async ({ asRole, arrange }) => {
        test.slow(); // PDFs are rendered on request
        const { receivable_id, invoice } = arrange('reports.receivable');
        const page = await asRole(role('manager'));

        await page.goto(`/dashboard/receivables/${receivable_id}`);
        await expect(page.getByText(invoice).first()).toBeVisible();
        await page.getByRole('button', { name: 'Preview / PDF' }).click();
        await expect(page.getByText('Preview Nota Barang')).toBeVisible();
        const link = page.getByRole('link', { name: 'PDF / Cetak' });
        await expect(link).toHaveAttribute('href', new RegExp(`/documents/receivables/${receivable_id}/pdf$`));
        await expectPdf(page, await link.getAttribute('href'), `piutang-${invoice}.pdf`);
    });

    test('roles and outlets: the cashier may print, stock-opname may not, another outlet is not found', async ({ asRole, arrange }) => {
        test.slow(); // PDFs are rendered on request
        const own = arrange('reports.receivable');
        const other = arrange('reports.receivable', { outlet: 'other' });
        const url = (id) => `/dashboard/documents/receivables/${id}/pdf`;

        const cashier = await asRole(role('cashier'));
        await expectPdf(cashier, url(own.receivable_id), `piutang-${own.invoice}.pdf`);

        const opname = await asRole(role('stock-opname'));
        expect((await opname.request.get(url(own.receivable_id))).status()).toBe(403);

        const manager = await asRole(role('manager'));
        expect((await manager.request.get(url(other.receivable_id))).status()).toBe(404);
    });
});
