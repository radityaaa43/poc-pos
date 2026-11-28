import { expect } from '@playwright/test';

// The offline queue lives in IndexedDB (resources/js/Utils/offlineDb.js: db `pos-offline`, store
// `pending_transactions`); the page has no other hook to it, so tests read it directly.
export const readQueue = (page) => page.evaluate(() => new Promise((resolve, reject) => {
    const open = indexedDB.open('pos-offline');
    open.onerror = () => reject(open.error);
    open.onsuccess = () => {
        const db = open.result;
        const request = db.transaction('pending_transactions').objectStore('pending_transactions').getAll();
        request.onsuccess = () => { db.close(); resolve(request.result); };
        request.onerror = () => reject(request.error);
    };
}));

// Opens the POS with one product in the server cart and a customer chosen, ready to pay cash.
export async function fillCart(page, { title, customer }) {
    await page.goto('/dashboard/transactions');
    await page.getByPlaceholder(/Cari produk/).fill(title);
    await page.getByRole('button', { name: new RegExp(title) }).first().click();
    await expect(page.getByText(`${title} ditambahkan`)).toBeVisible();
    await page.getByText('Pilih pelanggan...').click();
    await page.getByPlaceholder(/Cari nama/).fill(customer);
    await page.getByRole('button', { name: new RegExp(customer) }).first().click();
    await page.getByRole('button', { name: 'Uang pas' }).click();
    await expect(page.getByRole('button', { name: 'Selesaikan Transaksi' })).toBeEnabled();
}

// A sale queued offline that the server then refuses: the shelf is emptied before the connection returns.
// Resolves once the row is back in the queue as failed, with the server's reason.
export async function queueRefusedSale({ asRole, arrange, uniq, role }) {
    const title = uniq('Kopi');
    const customer = uniq('Budi');
    const { product_id } = arrange('product', { title, stock: 10 });
    arrange('offline.customer', { name: customer });
    arrange('offline.clear-cart');
    const { shift_id } = arrange('open-shift', { cash: 100000 });
    const page = await asRole(role);
    await fillCart(page, { title, customer });
    await page.context().setOffline(true);
    await page.getByRole('button', { name: 'Selesaikan Transaksi' }).click();
    await expect.poll(() => readQueue(page)).toHaveLength(1);
    arrange('pos.set-stock', { product_id, stock: 0 });
    await page.context().setOffline(false);
    await expect.poll(async () => (await readQueue(page))[0]?.status).toBe('failed');
    const [queued] = await readQueue(page);

    return { page, title, product_id, shift_id, queued };
}
