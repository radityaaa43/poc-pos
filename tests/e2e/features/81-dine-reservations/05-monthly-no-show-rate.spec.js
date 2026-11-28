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
        // The card is a description list: each label sits in a dt with its value in the next dd.
        const value = (label) => card.locator('dt').filter({ hasText: new RegExp(`^${label}:?$`) }).locator('xpath=following-sibling::dd');
        await expect(value('Belum ditandai')).toHaveText('3');
        await expect(value('Tingkat tidak datang')).toHaveText('—');

        await mark(guests[0], 'Datang');
        await mark(guests[1], 'Batal');
        await mark(guests[2], 'Tidak datang');

        await expect(value('Belum ditandai')).toHaveText('0');
        await expect(value('Tingkat tidak datang')).toHaveText('50%');
        await expect(value('Datang')).toHaveText('1');
        await expect(value('Batal')).toHaveText('1');
        await expect(value('Tidak datang')).toHaveText('1');
    });
});
