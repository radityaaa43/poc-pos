import { test, expect } from '../../support/fixtures.js';
import { role } from '../../support/inventory.js';

const URL = '/dashboard/dine-reservations';

// REQ-009 success metric "Reservasi": the month of the shown date gains the counts and the no-show rate.
test.describe('81-dine-reservations: monthly no-show rate', () => {
    test('@BR-1 a manager sees the month summary move as reservations are marked', async ({ asRole, arrange, uniq }) => {
        const table = arrange('dine.table', { name: uniq('R') });
        const guests = [uniq('Hana'), uniq('Indra'), uniq('Joko')];
        // 400 days ahead: a month no other step uses, so the counts are exact in the shared database.
        let date;
        for (const name of guests) ({ date } = arrange('dine.reservation', { table_id: table.table_id, name, days: 400 }));

        const page = await asRole(role('manager'));
        const mark = async (name, action) => {
            await page.getByRole('button', { name: `${action}: ${name}`, exact: true }).click();
            await page.locator('.swal2-popup').getByRole('button', { name: action, exact: true }).click();
        };

        await page.goto(`${URL}?date=${date}`);
        const card = page.getByRole('region', { name: /Ringkasan/ });
        await expect(card).toContainText('Belum ditandai 3');
        await expect(card).toContainText('Tingkat tidak datang —');

        await mark(guests[0], 'Datang');
        await mark(guests[1], 'Batal');
        await mark(guests[2], 'Tidak datang');

        await expect(card).toContainText('Belum ditandai 0');
        await expect(card).toContainText('Tingkat tidak datang 50%');
        for (const count of ['Datang 1', 'Batal 1', 'Tidak datang 1']) {
            await expect(card).toContainText(count);
        }
    });
});
