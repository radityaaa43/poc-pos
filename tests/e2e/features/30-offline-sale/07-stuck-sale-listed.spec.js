import { test, expect } from '../../support/fixtures.js';
import { queueRefusedSale } from '../../support/offline-pos.js';
import { cashier } from '../../support/shift-close.js';

test.describe('offline sale: stuck sale', () => {
    test('a sale the server refused is listed on the POS with its reason', async ({ asRole, arrange, uniq }) => {
        test.setTimeout(120_000);
        const { page, title, queued } = await queueRefusedSale({ asRole, arrange, uniq, role: cashier });

        await page.getByRole('button', { name: 'Transaksi offline tertunda (1)' }).click();

        const dialog = page.getByRole('dialog');
        await expect(dialog.getByText('Gagal', { exact: true })).toBeVisible();
        expect(queued.last_error).toBeTruthy();
        await expect(dialog.getByText(queued.last_error)).toBeVisible();
        await expect(dialog.getByText(/10\.000 · Tunai/)).toBeVisible();
        // Named from the queue record: after a reload a sold-out product is no longer in the POS list.
        await expect(dialog.getByText(`1 × ${title}`)).toBeVisible();
        await expect(dialog.getByRole('button', { name: 'Coba sinkron sekarang' })).toBeEnabled();
        await expect(dialog.getByRole('button', { name: 'Hapus' })).toBeEnabled();
    });
});
