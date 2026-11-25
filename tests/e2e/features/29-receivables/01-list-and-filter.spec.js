import { test, expect as baseExpect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';

// Pages are slow when several agents share one machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 30_000 });

const manager = roles.find((role) => role.key === 'manager');
const opname = roles.find((role) => role.key === 'stock-opname');

test.describe('29-receivables', () => {
    // Multi-page flows: the shared 4-CPU machine can take longer than the default 30 s.
    test.describe.configure({ timeout: 120_000 });
    test.use({ actionTimeout: 30_000 });

    test('the list shows total, remaining, due date and the right status badge', async ({ asRole, arrange, uniq }) => {
        const open = arrange('posops.receivable', { total: 100000, customer: uniq('Andi') });
        const partial = arrange('posops.receivable', { total: 100000, paid: 30000, customer: uniq('Bima') });
        const late = arrange('posops.receivable', { total: 100000, due_days: -5, customer: uniq('Citra') });
        const page = await asRole(manager);

        const row = (receivable) => page.getByRole('link').filter({ hasText: receivable.invoice });
        for (const [receivable, remaining, status] of [[open, 100000, 'Belum Lunas'], [partial, 70000, 'Parsial'], [late, 100000, 'Jatuh Tempo']]) {
            await page.goto(`/dashboard/receivables?invoice=${receivable.invoice}`);
            await expect(row(receivable)).toHaveCount(1);
            await expect(row(receivable)).toContainText(receivable.customer);
            await expect(row(receivable)).toContainText('Rp 100.000');
            await expect(row(receivable)).toContainText(`Rp ${remaining.toLocaleString('id-ID')}`);
            await expect(row(receivable)).toContainText(status);
        }
    });

    test('the filters narrow the list by invoice and status', async ({ asRole, arrange, uniq }) => {
        const partial = arrange('posops.receivable', { total: 100000, paid: 30000, customer: uniq('Dewi') });
        const page = await asRole(manager);

        await page.goto('/dashboard/receivables');
        await page.getByPlaceholder('Cari invoice / nomor nota').fill(partial.invoice);
        await page.getByRole('combobox').selectOption('partial');
        await page.getByRole('button', { name: 'Terapkan' }).click();
        await expect(page.getByRole('link').filter({ hasText: partial.invoice })).toHaveCount(1);

        await page.getByRole('combobox').selectOption('paid');
        await page.getByRole('button', { name: 'Terapkan' }).click();
        await expect(page.getByText('Belum ada data nota barang.')).toBeVisible();
        await expect(page.getByRole('link').filter({ hasText: partial.invoice })).toHaveCount(0);
    });

    test('an overdue receivable is found by the "Jatuh Tempo" filter', async ({ asRole, arrange, uniq }) => {
        const late = arrange('posops.receivable', { total: 100000, due_days: -5, customer: uniq('Eko') });
        const page = await asRole(manager);

        await page.goto('/dashboard/receivables');
        await page.getByPlaceholder('Cari invoice / nomor nota').fill(late.invoice);
        await page.getByRole('combobox').selectOption('overdue');
        await page.getByRole('button', { name: 'Terapkan' }).click();
        await expect(page.getByRole('link').filter({ hasText: late.invoice })).toHaveCount(1);
    });

    test('a role without receivable access gets a 403', async ({ asRole }) => {
        const page = await asRole(opname);

        const response = await page.goto('/dashboard/receivables');
        expect(response.status()).toBe(403);
    });
});
