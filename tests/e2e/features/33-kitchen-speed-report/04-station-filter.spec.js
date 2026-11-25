import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');

// The report adds up the whole period, so each step takes a day of its own (random, in a year no other step uses):
// a rerun on one database must not add to it. The station arg of kitchen.speed-items puts its three finished items
// (and the cancelled / not collected ones the report leaves out) at that station.
const randomDay = () => `2033-${String(1 + Math.floor(Math.random() * 12)).padStart(2, '0')}-${String(5 + Math.floor(Math.random() * 20)).padStart(2, '0')}`;

async function openReport(page, day, station = null) {
    await page.goto('/dashboard/reports/kitchen-speed');
    await page.getByLabel('Tanggal Mulai').fill(day);
    await page.getByLabel('Tanggal Akhir').fill(day);
    if (station !== null) {
        await page.getByLabel('Stasiun', { exact: true }).selectOption({ label: station });
    }
    await page.getByRole('button', { name: 'Terapkan' }).click();
}

const rowOf = (page, title) => page.getByRole('row').filter({ hasText: title });

test.describe('kitchen speed report: station filter', () => {
    test('the Stasiun filter keeps only that station\'s menus; the handover card stays whole-order and says so', { tag: ['@REQ-006-AC-08'] }, async ({ asRole, arrange, uniq }) => {
        const day = randomDay();
        const bar = arrange('kitchen.speed-items', { date: day, station: uniq('Bar') });
        const pastry = arrange('kitchen.speed-items', { date: day, station: uniq('Pastry') });
        const page = await asRole(manager);

        // No filter: both menus, each with its station, and the default option is "Semua stasiun".
        await openReport(page, day);
        await expect(page.getByRole('columnheader', { name: 'Stasiun' })).toBeVisible();
        await expect(page.getByLabel('Stasiun', { exact: true }).locator('option:checked')).toHaveText('Semua stasiun');
        await expect(rowOf(page, bar.title).getByRole('cell').nth(0)).toHaveText(bar.station);
        await expect(rowOf(page, pastry.title).getByRole('cell').nth(0)).toHaveText(pastry.station);
        const card = page.getByRole('region', { name: 'Waktu bayar sampai diserahkan' });
        await expect(card).toContainText('7,0 mnt');
        await expect(card).toContainText('Jumlah pesanan: 6');
        await expect(page.getByText('Semua pesanan dari semua stasiun')).toHaveCount(0);

        // Bar only: its menu with its own numbers, not the other station's menu.
        await openReport(page, day, bar.station);
        await expect(page.getByText('Semua pesanan dari semua stasiun')).toBeVisible();
        await expect(rowOf(page, pastry.title)).toHaveCount(0);
        const cells = rowOf(page, bar.title).getByRole('cell');
        await expect(cells.nth(0)).toHaveText(bar.station);
        await expect(cells.nth(1)).toHaveText('3'); // Jumlah pesanan
        await expect(cells.nth(2)).toHaveText('5'); // Jumlah porsi
        await expect(cells.nth(3)).toHaveText('4,0 mnt'); // Median buat
        await expect(cells.nth(8)).toHaveText('66,7%'); // Dalam target

        // The handover figure is the same with and without the filter: all orders of all stations.
        await expect(card).toContainText('7,0 mnt');
        await expect(card).toContainText('Jumlah pesanan: 6');
    });

    test('a deleted station keeps its name in the column and can still be filtered, labelled (dihapus)', { tag: ['@REQ-006-AC-08'] }, async ({ asRole, arrange, uniq }) => {
        const day = randomDay();
        const old = arrange('kitchen.speed-items', { date: day, station: uniq('Panggang') });
        arrange('kitchen.station-delete', { name: old.station });
        const page = await asRole(manager);
        const label = `${old.station} (dihapus)`;

        await openReport(page, day);
        await expect(rowOf(page, old.title).getByRole('cell').nth(0)).toHaveText(label);
        await expect(page.getByLabel('Stasiun', { exact: true }).locator('option', { hasText: label })).toHaveCount(1);

        await openReport(page, day, label);
        const cells = rowOf(page, old.title).getByRole('cell');
        await expect(cells.nth(0)).toHaveText(label);
        await expect(cells.nth(1)).toHaveText('3');
        await expect(cells.nth(2)).toHaveText('5');
        await expect(cells.nth(8)).toHaveText('66,7%');
    });
});
