import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

const manager = roles.find((role) => role.key === 'manager');

const pastDay = () => `2024-${String(1 + Math.floor(Math.random() * 12)).padStart(2, '0')}-${String(1 + Math.floor(Math.random() * 28)).padStart(2, '0')}`;

async function openReport(page, start, end = start) {
    await page.goto('/dashboard/reports/kitchen-unprinted');
    await page.getByLabel('Tanggal Mulai').fill(start);
    await page.getByLabel('Tanggal Akhir').fill(end);
    await page.getByRole('button', { name: 'Terapkan' }).click();
}

test.describe('kitchen unprinted report: Coba lagi clears a ticket', () => {
    test('a ticket printed later through the prints endpoint leaves the list', { tag: ['@REQ-004-AC-08'] }, async ({ asRole, arrange }) => {
        const date = pastDay();
        const arranged = arrange('kitchen.unprinted-items', { date, count: 1 });
        const page = await asRole(manager);

        await openReport(page, date);
        await expect(page.getByRole('row', { name: new RegExp(arranged.invoice) })).toBeVisible();

        // Coba lagi prints the normal ticket and records it, as the kitchen display does on retry.
        const status = await page.evaluate(async ({ deviceId, itemIds }) => {
            const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')).split('=')[1]);
            const response = await fetch(`/dashboard/kitchen/print-devices/${deviceId}/prints`, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf },
                body: JSON.stringify({ item_ids: itemIds }),
            });

            return response.status;
        }, { deviceId: arranged.device_id, itemIds: arranged.item_ids });
        expect(status).toBe(204);

        await openReport(page, date);
        await expect(page.getByText('Semua tiket pada periode ini tercetak')).toBeVisible();
        await expect(page.getByText(arranged.invoice)).toHaveCount(0);
    });
});
