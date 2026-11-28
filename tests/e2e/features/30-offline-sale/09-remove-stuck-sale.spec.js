import { test, expect } from '../../support/fixtures.js';
import { queueRefusedSale, readQueue } from '../../support/offline-pos.js';
import { cashier } from '../../support/shift-close.js';
import { roles } from '../../support/roles.js';
import { roleOf } from '../../support/crm.js';

const NOT_ON_SERVER = 'Transaksi ini belum tercatat di server. Catat ulang secara online dulu, lalu hapus dari sini.';
const CONFIRM = 'Hapus transaksi offline ini dari perangkat? Penghapusan dicatat di audit log.';

test.describe('offline sale: remove', () => {
    test('a stuck sale leaves the device only after its audit entry is written, and then no longer holds the close', async ({ asRole, arrange, uniq }) => {
        test.setTimeout(180_000);
        const { page, shift_id, queued } = await queueRefusedSale({ asRole, arrange, uniq, role: cashier });
        // A second tab of the same browser: it shares the queue.
        const shift = await page.context().newPage();
        await shift.goto(`/dashboard/cashier-shifts/${shift_id}`);
        await expect(shift.getByRole('button', { name: 'Sinkronkan transaksi offline dulu' })).toBeDisabled();

        await page.bringToFront();
        await page.getByRole('button', { name: 'Transaksi offline tertunda (1)' }).click();
        const dialog = page.getByRole('dialog');
        const remove = dialog.getByRole('button', { name: 'Hapus', exact: true });
        const confirmRemoval = async () => {
            await remove.click();
            await expect(dialog.getByText(NOT_ON_SERVER)).toBeVisible();
            await expect(dialog.getByText(CONFIRM)).toBeVisible();
            await remove.click();
        };

        await page.context().setOffline(true);
        await expect(remove).toBeDisabled();
        await expect(dialog.getByText('Hapus hanya bisa saat online.')).toBeVisible();
        // Online again, but the audit call fails: the row stays. Reconnecting also re-runs the automatic sync.
        const discardUrl = /transactions\/offline-queue\/discard/;
        await page.route(discardUrl, (route) => route.fulfill({ status: 500, json: { message: 'down' } }));
        await page.context().setOffline(false);
        await expect.poll(async () => (await readQueue(page))[0]).toMatchObject({ status: 'failed', attempts: queued.attempts + 1 });
        await confirmRemoval();
        await expect(dialog.getByRole('alert')).toHaveText('Gagal menghapus. Transaksi tetap tersimpan di perangkat.');
        expect((await readQueue(page))[0]).toMatchObject({ client_uuid: queued.client_uuid, status: 'failed' });

        await page.unroute(discardUrl);
        await confirmRemoval();
        await expect(dialog.getByText('Tidak ada transaksi offline yang tertunda.')).toBeVisible();
        expect(await readQueue(page)).toHaveLength(0);

        await shift.reload();
        await expect(shift.getByRole('button', { name: 'Finalisasi Closing' })).toBeEnabled();

        const manager = await asRole(roleOf(roles, 'manager'));
        await manager.goto('/dashboard/audit-logs');
        const search = manager.getByPlaceholder('Cari target atau deskripsi');
        await search.fill('Transaksi offline dihapus dari antrean perangkat.');
        await search.press('Enter');
        // Newest first: this step's entry.
        const row = manager.getByRole('table').getByRole('row').filter({ hasText: 'offline_sale.discarded' }).first();
        await expect(row).toBeVisible();
        await row.getByRole('link', { name: 'Detail' }).click();
        const meta = manager.getByRole('heading', { name: 'Meta' }).locator('xpath=..');
        await expect(meta).toContainText(queued.client_uuid);
        // The whole queued sale, so it can be rung up again.
        await expect(meta).toContainText(/grand_total\W*10000/);
        await expect(meta).toContainText(/cash\W*10000/);
        await expect(meta).toContainText(/order_type\W*in_store/);
    });
});
