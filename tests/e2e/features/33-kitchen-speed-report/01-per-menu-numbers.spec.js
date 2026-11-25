import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');

// The report is read by day; every step arranges its own day so a rerun on one database does not add up.
async function openReport(page, start, end = start) {
    await page.goto('/dashboard/reports/kitchen-speed');
    await page.getByLabel('Tanggal Mulai').fill(start);
    await page.getByLabel('Tanggal Akhir').fill(end);
    await page.getByRole('button', { name: 'Terapkan' }).click();
}

test.describe('kitchen speed report: per menu', () => {
    test('an item shows its counts, medians, averages, target and share within target', { tag: ['@REQ-003-AC-15'] }, async ({ asRole, arrange }) => {
        const item = arrange('kitchen.speed-items', { date: '2025-04-14' });
        const page = await asRole(manager);

        await openReport(page, item.date);

        // The layout header and the page both carry the title.
        await expect(page.getByRole('heading', { name: 'Laporan Kecepatan Dapur' }).first()).toBeVisible();
        const cells = page.getByRole('row').filter({ hasText: item.title }).getByRole('cell');
        // Jumlah pesanan counts rows, Jumlah porsi the summed quantity (a line of 3 is one order, three portions).
        await expect(cells.nth(0)).toHaveText('3');
        await expect(cells.nth(1)).toHaveText('5');
        await expect(cells.nth(2)).toHaveText('4,0 mnt'); // Median buat
        await expect(cells.nth(3)).toHaveText('4,7 mnt'); // Rata-rata buat
        await expect(cells.nth(4)).toHaveText('5,0 mnt'); // Median tunggu + buat
        await expect(cells.nth(5)).toHaveText('6,0 mnt'); // Rata-rata tunggu + buat
        await expect(cells.nth(6)).toHaveText('5,0 mnt'); // Target
        await expect(cells.nth(7)).toHaveText('66,7%'); // Dalam target
    });

    test('a period without kitchen data says so instead of showing zeros', { tag: ['@REQ-003-AC-15'] }, async ({ asRole }) => {
        const page = await asRole(manager);

        await openReport(page, '2025-04-26');

        await expect(page.getByText('Belum ada data dapur untuk periode ini')).toBeVisible();
        await expect(page.getByRole('table')).toHaveCount(0);
    });
});
