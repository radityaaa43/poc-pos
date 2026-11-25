import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { expectPdf } from '../../support/reports.js';

const role = (key) => roles.find((candidate) => candidate.key === key);
const documents = (invoice) => [
    `/dashboard/documents/transactions/${invoice}/pdf/invoice`,
    `/dashboard/documents/transactions/${invoice}/pdf/receipt/80`,
    `/dashboard/documents/transactions/${invoice}/pdf/shipping`,
    `/dashboard/documents/transactions/${invoice}/print/thermal`,
];

test.describe('transaction documents: who may open them', () => {
    test('a sale of another outlet is not found for a manager of this outlet', async ({ asRole, arrange }) => {
        test.slow(); // PDFs are rendered on request
        const { a1, b1 } = arrange('reports.sales');
        const page = await asRole(role('manager'));

        for (const url of documents(b1)) {
            expect((await page.request.get(url)).status(), url).toBe(404);
        }
        expect((await page.request.get(`/dashboard/documents/transactions/${a1}/pdf/invoice`)).status()).toBe(200);
    });

    test('the cashier role may print, the stock-opname role may not, a guest is sent to login', async ({ asRole, arrange, request }) => {
        test.slow(); // PDFs are rendered on request
        const { a1 } = arrange('reports.sales');

        const cashier = await asRole(role('cashier'));
        await expectPdf(cashier, `/dashboard/documents/transactions/${a1}/pdf/invoice`, `invoice-${a1}.pdf`);

        const opname = await asRole(role('stock-opname'));
        expect((await opname.request.get(`/dashboard/documents/transactions/${a1}/pdf/invoice`)).status()).toBe(403);

        const guest = await request.get(`/dashboard/documents/transactions/${a1}/pdf/invoice`, { maxRedirects: 0 });
        expect(guest.status()).toBe(302);
        expect(guest.headers().location).toContain('/login');
    });
});
