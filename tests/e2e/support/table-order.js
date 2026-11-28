import { expect } from '@playwright/test';
import { addToCart, openPos } from './pos.js';

// The POS "Pesanan meja" dialog (REQ-009). The cashier needs an open shift (`arrange('pos.open-shift')`).
export async function openTables(page) {
    await page.getByRole('button', { name: 'Pesanan meja', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('listitem').first()).toBeVisible();

    return dialog;
}

export const tableButton = (dialog, name) => dialog.getByRole('button', { name: new RegExp(name) });

// Puts one `product` in the cart and sends it to the table; `choose` names the radios to pick first.
export async function sendToTable(page, { product, table, choose = [] }) {
    await openPos(page);
    await addToCart(page, product);
    const dialog = await openTables(page);
    await tableButton(dialog, table).click();
    for (const name of choose) {
        await dialog.getByRole('radio', { name }).check();
    }
    await dialog.getByRole('button', { name: 'Kirim pesanan' }).click();
    await expect(page.getByText(`Pesanan terkirim ke ${table}`)).toBeVisible();
    await expect(dialog).toBeHidden();
}
