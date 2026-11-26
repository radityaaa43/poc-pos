import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');

// The report adds up the whole period, so each step takes a day of its own that no other step, test or rerun on the
// same database can share: a day in 2034-2037 (no other spec uses those years; TIMESTAMP columns end in 2038 on
// MySQL), chosen from the second the step runs at plus a counter for a second test in the same second. The day is
// recorded in the test annotations. The station arg of kitchen.speed-items puts its three finished items (and the
// cancelled / not collected ones the report leaves out) at that station.
let taken = 0;
const freshDay = () => {
    const offset = (Math.floor(Date.now() / 1000) + taken++) % 1400;
    const day = new Date(Date.UTC(2034, 0, 1) + offset * 86_400_000).toISOString().slice(0, 10);
    test.info().annotations.push({ type: 'report day', description: day });

    return day;
};

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
        const day = freshDay();
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
        const day = freshDay();
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
