import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const manager = roles.find((role) => role.key === 'manager');

test.describe('29-receivables', () => {
    test.describe.configure({ timeout: 180_000 });
    test.use({ actionTimeout: 30_000 });

    test('each credit filter lists only its customers', { tag: ['@REQ-007-AC-07'] }, async ({ asRole, arrange, uniq }) => {
        const owing = (total, limit) => {
            const { customer_id } = arrange('pos.customer', { name: uniq('Saring') });
            arrange('paylater.credit', { customer_id, ...(limit === undefined ? {} : { limit }) });

            return arrange('posops.receivable', { customer_id, total });
        };
        const half = owing(500000, 1000000);
        const near = owing(800000, 1000000);
        const over = owing(1200000, 1000000);
        const unlimited = owing(500000);
        const page = await asRole(manager);
        await page.goto('/dashboard/receivables');

        const expected = {
            'Mendekati batas (≥ 80%)': [near, over],
            'Melewati batas': [over],
            'Tanpa batas': [unlimited],
        };
        for (const [label, listed] of Object.entries(expected)) {
            // Other steps leave receivables too, so each invoice is searched for together with the filter.
            for (const receivable of [half, near, over, unlimited]) {
                await page.getByPlaceholder('Cari invoice / nomor nota').fill(receivable.invoice);
                await page.getByRole('combobox', { name: 'Batas piutang' }).selectOption({ label });
                await page.getByRole('button', { name: 'Terapkan' }).click();
                // The next search is for another invoice, so the URL says when this answer has arrived.
                await expect(page).toHaveURL(new RegExp(`invoice=${receivable.invoice}(&|$)`));
                const row = page.getByRole('link').filter({ hasText: receivable.invoice });
                if (listed.includes(receivable)) {
                    await expect(row, label).toHaveCount(1);
                } else {
                    await expect(page.getByText('Belum ada data nota barang.'), label).toBeVisible();
                    await expect(row, label).toHaveCount(0);
                }
            }
        }
    });
});
