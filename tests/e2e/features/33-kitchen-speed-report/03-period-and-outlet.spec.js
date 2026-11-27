import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');

test.describe('kitchen speed report: period', () => {
    test('items count on the day they were accepted; cancelled and not collected items are left out', { tag: ['@REQ-003-AC-26'] }, async ({ asRole, arrange }) => {
        // A day of its own (random, in a year no other step uses), so the days around it are empty even on a rerun.
        const month = String(1 + Math.floor(Math.random() * 12)).padStart(2, '0');
        const dayNumber = 5 + Math.floor(Math.random() * 20);
        const at = (offset) => `2032-${month}-${String(dayNumber + offset).padStart(2, '0')}`;
        const item = arrange('kitchen.speed-items', { date: at(0) });
        const page = await asRole(manager);
        const show = async (start, end) => {
            await page.goto('/dashboard/reports/kitchen-speed');
            await page.getByLabel('Tanggal Mulai').fill(start);
            await page.getByLabel('Tanggal Akhir').fill(end);
            await page.getByRole('button', { name: 'Terapkan' }).click();
        };

        // The day before and the day after: nothing.
        await show(at(-1), at(-1));
        await expect(page.getByText('Belum ada data dapur untuk periode ini')).toBeVisible();
        await show(at(1), at(1));
        await expect(page.getByText('Belum ada data dapur untuk periode ini')).toBeVisible();

        // The day itself, inside a wider range: 3 finished orders; the cancelled one and the not collected one stay out.
        await show(at(-1), at(1));
        const cells = page.getByRole('row').filter({ hasText: item.title }).getByRole('cell');
        await expect(cells.nth(1)).toHaveText('3');
        await expect(cells.nth(2)).toHaveText('5');
    });
});
