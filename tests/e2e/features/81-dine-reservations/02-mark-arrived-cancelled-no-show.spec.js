import { test, expect } from '../../support/fixtures.js';
import { role } from '../../support/inventory.js';

const URL = '/dashboard/dine-reservations';
const row = (page, name) => page.getByRole('listitem').filter({ hasText: name });
// Only the values: a booked card also has a button called "Datang".
const status = (page, name, text) => row(page, name).locator('dd').getByText(text, { exact: true });

async function mark(page, name, action) {
    await page.getByRole('button', { name: `${action}: ${name}`, exact: true }).click();
    await page.locator('.swal2-popup').getByRole('button', { name: action, exact: true }).click();
}

// REQ-009 AC-18 and decision 8: only a mark by staff changes a reservation.
test.describe('81-dine-reservations: mark a reservation', () => {
    test('@REQ-009-AC-18 staff mark Datang, Tidak datang and Batal; an unmarked one stays, even from an earlier day', async ({ asRole, arrange, uniq }) => {
        const table = arrange('dine.table', { name: uniq('R') });
        const marks = [[uniq('Andi'), 'Datang'], [uniq('Beni'), 'Tidak datang'], [uniq('Cici'), 'Batal']];
        for (const [name] of marks) arrange('dine.reservation', { table_id: table.table_id, name });
        const forgotten = uniq('Dodi');
        arrange('dine.reservation', { table_id: table.table_id, name: forgotten, days: -3 });

        const page = await asRole(role('manager'));
        await page.goto(URL);
        for (const [name, action] of marks) {
            await mark(page, name, action);
            await expect(status(page, name, action)).toBeVisible();
            await expect(row(page, name).getByRole('button')).toHaveCount(0);
        }

        // Nobody marked it: three days later it is still booked and today's list still offers the marks.
        await expect(status(page, forgotten, 'Dicatat')).toBeVisible();
        await expect(page.getByRole('button', { name: `Datang: ${forgotten}`, exact: true })).toBeVisible();
    });

    test('@REQ-009-AC-18 of two staff marking one reservation the first wins and the second is told', async ({ asRole, arrange, uniq }) => {
        const table = arrange('dine.table', { name: uniq('R') });
        const name = uniq('Eka');
        arrange('dine.reservation', { table_id: table.table_id, name });
        const first = await asRole(role('manager'));
        const second = await asRole(role('manager'));
        await first.goto(URL);
        await second.goto(URL);

        await mark(first, name, 'Datang');
        await expect(status(first, name, 'Datang')).toBeVisible();

        await mark(second, name, 'Batal');
        await expect(second.getByText('Reservasi ini sudah ditandai dan tidak bisa diubah lagi.')).toBeVisible();
        await expect(status(second, name, 'Datang')).toBeVisible();
    });
});
