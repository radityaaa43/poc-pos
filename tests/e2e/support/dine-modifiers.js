import { expect } from '@playwright/test';

// The guest opens the table menu, picks the given option, the required Gula option and a note, adds it and opens the
// cart. Returns the cart dialog (a native <dialog>), ready for the spec to press a submit button.
export async function addMenuWithOptions(page, { token, title, option, note }) {
    await page.goto(`/dine/${token}`);
    const card = page.locator('div.rounded-xl', { has: page.getByRole('heading', { name: title, exact: true }) });
    await card.getByRole('button', { name: '+ Tambah' }).click();

    const panel = page.getByRole('dialog', { name: title });
    if (option) await panel.getByLabel(new RegExp(`^${option}`)).check();
    // The seed's Gula default (Normal) sells out, so the required group must pick the free Less.
    await panel.getByLabel(/^Less/).check();
    if (note) await panel.getByRole('textbox').fill(note);
    await panel.getByRole('button', { name: 'Tambah ke pesanan' }).click();
    await expect(panel).toBeHidden();

    await page.getByRole('button', { name: /1 item/ }).click();

    return page.locator('#cart-modal');
}
