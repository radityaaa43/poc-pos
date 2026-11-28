import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { readXlsx } from '../../support/reports.js';

const role = (key) => roles.find((candidate) => candidate.key === key);
const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

async function exportRows(page, query) {
    const response = await page.request.get(`/dashboard/export/transactions?${query}`);
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain(XLSX);
    expect(response.headers()['content-disposition']).toContain('filename=transaksi.xlsx');

    return readXlsx(await response.body());
}

// No page links to /dashboard/export/transactions, so the step downloads it with the signed-in session.
test.describe('exports: transactions workbook', () => {
    test('rows carry the arranged sales, scoped to the outlets of the user', async ({ asRole, arrange }) => {
        const { a1, a2, b1, customer } = arrange('reports.sales');

        const manager = await asRole(role('manager'));
        const rows = await exportRows(manager, 'start_date=2025-03-01&end_date=2025-03-31');
        expect(rows[0]).toEqual(['Invoice', 'Tanggal', 'Kasir', 'Pelanggan', 'Metode', 'Status', 'Subtotal', 'Diskon', 'Ongkir', 'Service Charge', 'Jenis Pajak', 'Pajak', 'Grand Total']);
        const byInvoice = Object.fromEntries(rows.slice(1).map((row) => [row[0], row]));
        expect(byInvoice[a1].slice(0, 6)).toEqual([a1, '2025-03-10 10:15:00', 'E2E Cashier', 'Umum', 'cash', 'paid']);
        // Zero amounts are blank cells (Laravel Excel's strict_null_comparison is off).
        expect(byInvoice[a1].slice(6)).toEqual(['35000', '', '', '', 'PPN', '', '35000']);
        expect(byInvoice[a2].slice(0, 6)).toEqual([a2, '2025-03-20 14:30:00', 'E2E Manager', customer, 'cash', 'paid']);
        expect(byInvoice[a2].slice(7)).toEqual(['3000', '', '', 'PPN', '', '27000']);
        expect(byInvoice[b1], 'a sale of an outlet the manager is not assigned to').toBeUndefined();

        // The date range is honoured: 11-31 March leaves out A1.
        const later = (await exportRows(manager, 'start_date=2025-03-11&end_date=2025-03-31')).map((row) => row[0]);
        expect(later).toContain(a2);
        expect(later).not.toContain(a1);

        // A super admin is not restricted to an outlet.
        const admin = await asRole(role('super-admin'));
        const all = (await exportRows(admin, 'start_date=2025-03-01&end_date=2025-03-31')).map((row) => row[0]);
        expect(all).toEqual(expect.arrayContaining([a1, a2, b1]));
    });

    test('Subtotal adds up: Subtotal - Diskon + Ongkir + Service Charge + Pajak = Grand Total', async ({ asRole, arrange }) => {
        const { a2 } = arrange('reports.sales');
        const manager = await asRole(role('manager'));

        const row = (await exportRows(manager, 'start_date=2025-03-20&end_date=2025-03-20')).find((cells) => cells[0] === a2);
        expect(row.slice(6)).toEqual(['30000', '3000', '', '', 'PPN', '', '27000']);
    });

    test('the cashier role may export, stock-opname may not', async ({ asRole }) => {
        const cashier = await asRole(role('cashier'));
        expect((await cashier.request.get('/dashboard/export/transactions?start_date=2025-03-10&end_date=2025-03-10')).status()).toBe(200);

        const opname = await asRole(role('stock-opname'));
        expect((await opname.request.get('/dashboard/export/transactions')).status()).toBe(403);
    });
});
