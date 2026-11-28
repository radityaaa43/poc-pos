import { test, expect } from '../../support/fixtures.js';
import { role } from '../../support/inventory.js';

// REQ-009 AC-16: a reservation saves with every field; a second one on the same table less than 2 hours apart is refused.
test.describe('81-dine-reservations: record a reservation and refuse a clash', () => {
    test('@REQ-009-AC-16 a manager records and changes a reservation; one less than 2 hours later is refused', async ({ asRole, arrange, uniq }) => {
        const table = arrange('dine.table', { name: uniq('R') });
        const guest = uniq('Budi');
        const second = uniq('Sari');
        const page = await asRole(role('manager'));
        const dialog = page.getByRole('dialog');
        const row = (name) => page.getByRole('listitem').filter({ hasText: name });
        const time = dialog.getByLabel('Jam', { exact: true });
        const save = () => dialog.getByRole('button', { name: 'Simpan' }).click();

        // The date field starts on the list's date (today), so it is left as it is.
        const fill = async (name, at) => {
            await page.getByRole('button', { name: 'Tambah reservasi' }).click();
            await dialog.getByLabel('Nama tamu').fill(name);
            await dialog.getByLabel('Nomor HP').fill('0812 3456 7890');
            await dialog.getByLabel('Jumlah tamu').fill('4');
            await time.fill(at);
            await dialog.getByLabel('Meja', { exact: true }).selectOption(String(table.table_id));
            await dialog.getByLabel('Catatan').fill('Dekat jendela');
        };

        await page.goto('/dashboard/dine-reservations');
        await fill(guest, '15:00');
        await save();
        for (const text of ['15:00', table.table_name, '0812 3456 7890', 'Dekat jendela', 'Dicatat']) {
            await expect(row(guest)).toContainText(text);
        }

        // 90 minutes later on the same table: refused under Jam, naming the booking in the way.
        await fill(second, '16:30');
        await save();
        await expect(dialog.getByText(`Meja ini sudah direservasi atas nama ${guest}`)).toBeVisible();

        // Exactly 2 hours apart is allowed, and only this save adds a row (a second one would break the locator).
        await time.fill('17:00');
        await save();
        await expect(row(second)).toContainText('17:00');

        await page.getByRole('button', { name: `Ubah: ${guest}` }).click();
        await time.fill('14:30');
        await save();
        await expect(row(guest)).toContainText('14:30');
    });
});
