import { expect } from '@playwright/test';
import { cashInput, finish, openPos, rupiah, total } from './pos.js';

// Helpers of 48-modifiers: a `modifier-menu` sold and returned through the real pages.

/** One paid cash sale; identical choices merge into one line of `portions`. Returns the invoice. */
export async function sellMenu(page, title, { options, portions, amount, note }) {
    await openPos(page);
    const panel = page.getByRole('dialog', { name: title });
    for (let portion = 0; portion < portions; portion++) {
        await page.getByRole('button', { name: new RegExp(`^${title}`) }).click();
        for (const name of options) await panel.getByLabel(new RegExp(`^${name}`)).check();
        if (note) await panel.getByRole('textbox').fill(note);
        await panel.getByRole('button', { name: 'Tambah', exact: true }).click();
        await expect(panel).toBeHidden();
    }
    await expect(total(page)).toHaveText(rupiah(amount));
    await cashInput(page).fill(String(amount));

    return finish(page);
}

/** Opens a draft for part of a one-line sale; `check` sees the form before the draft is saved and completed. */
export async function returnPortions(page, invoice, portions, check = async () => {}) {
    await page.goto(`/dashboard/transactions/history?invoice=${invoice}`);
    await page.getByRole('link', { name: 'Retur', exact: true }).click();
    await page.getByRole('spinbutton').fill(String(portions));
    await page.getByPlaceholder('Alasan retur').fill('Salah pesan');
    await check();
    await page.getByRole('button', { name: 'Buat Draft' }).click();
    await page.getByRole('button', { name: 'Selesaikan Retur' }).click();
    await expect(page.getByText('Completed', { exact: true })).toBeVisible({ timeout: 30_000 });
}
