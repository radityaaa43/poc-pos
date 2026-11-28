import { test, expect } from '../../support/fixtures.js';
import { role, acceptDialogs } from '../../support/inventory.js';
import { wasteRow } from '../../support/waste.js';

test.describe('82-stock-waste', () => {
    // Another approver works a pending row, rejects another with a reason, and cancels a recorded one (AC-06/08/09).
    test('a manager approves one, rejects one with a reason and cancels a recorded one', { tag: ['@REQ-011-AC-06', '@REQ-011-AC-08', '@REQ-011-AC-09'] }, async ({ asRole, arrange, uniq }) => {
        const page = await asRole(role('manager'));
        acceptDialogs(page);
        const { bean_id } = arrange('inventory.recipe', { prefix: uniq('Wst'), beans: 1000 });
        const noteA = uniq('setuju');
        const noteB = uniq('tolak');
        const noteC = uniq('batal');
        arrange('waste.seed', { product_id: bean_id, qty: 10, note_a: noteA, note_b: noteB, note_c: noteC });

        await page.goto('/dashboard/stock-waste');
        const openRow = async (note) => {
            const row = wasteRow(page, note);
            await row.locator('summary').click();
            return row;
        };

        // Approve a pending row; the confirm() dialog is accepted, the list shows Tercatat.
        await (await openRow(noteA)).getByRole('button', { name: 'Setujui' }).click();
        await expect(wasteRow(page, noteA).getByText('Tercatat', { exact: true })).toBeVisible();

        // Reject another with a reason.
        await (await openRow(noteB)).getByRole('button', { name: 'Tolak' }).click();
        await page.getByLabel('Alasan').fill('Salah input');
        await page.getByRole('button', { name: 'Simpan' }).click();
        await expect(wasteRow(page, noteB).getByText('Ditolak', { exact: true })).toBeVisible();

        // Cancel the recorded one with a reason.
        await (await openRow(noteC)).getByRole('button', { name: 'Batalkan waste' }).click();
        await page.getByLabel('Alasan').fill('Salah catat');
        await page.getByRole('button', { name: 'Simpan' }).click();
        await expect(wasteRow(page, noteC).getByText('Dibatalkan', { exact: true })).toBeVisible();
    });
});
