import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');

// A past day each run, in a year no other step uses, so the days around it stay empty.
const pastDay = () => `2024-${String(1 + Math.floor(Math.random() * 12)).padStart(2, '0')}-${String(1 + Math.floor(Math.random() * 28)).padStart(2, '0')}`;

async function openReport(page, start, end = start) {
    await page.goto('/dashboard/reports/kitchen-unprinted');
    await page.getByLabel('Tanggal Mulai').fill(start);
    await page.getByLabel('Tanggal Akhir').fill(end);
    await page.getByRole('button', { name: 'Terapkan' }).click();
}

test.describe('kitchen unprinted report: a ticket that never printed', () => {
    test('the list shows the order, station, print device and time, and the day count', { tag: ['@REQ-004-AC-08'] }, async ({ asRole, arrange }) => {
        const date = pastDay();
        const arranged = arrange('kitchen.unprinted-items', { date, count: 1 });
        const page = await asRole(manager);

        await openReport(page, date);

        // The layout header and the page both carry the title.
        await expect(page.getByRole('heading', { name: 'Tiket Dapur Tidak Tercetak' }).first()).toBeVisible();

        // The day count: at least this ticket on its day.
        const dayRow = page.getByRole('table', { name: 'Jumlah per hari' }).getByRole('row').filter({ hasText: date });
        await expect(dayRow).toBeVisible();
        expect(Number(await dayRow.getByRole('cell').textContent())).toBeGreaterThanOrEqual(1);

        // One ticket row with the time, the order number, Dapur and the print device named for the day.
        const row = page.getByRole('table', { name: 'Tiket tidak tercetak' }).getByRole('row', { name: new RegExp(arranged.invoice) });
        await expect(row).toBeVisible();
        await expect(row).toContainText(date);
        await expect(row).toContainText('10:00');
        await expect(row).toContainText('Dapur');
        await expect(row).toContainText(arranged.device);
        await expect(row).toContainText('1');
    });

    test('a period with every ticket printed says so instead of listing rows', { tag: ['@REQ-004-AC-08'] }, async ({ asRole }) => {
        const page = await asRole(manager);

        await openReport(page, '2023-01-01');

        await expect(page.getByText('Semua tiket pada periode ini tercetak')).toBeVisible();
        await expect(page.getByRole('table')).toHaveCount(0);
    });
});
