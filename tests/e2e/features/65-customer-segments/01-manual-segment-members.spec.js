import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { field, roleOf } from '../../support/crm.js';

test.describe('customer segments: manual segment', () => {
    test('a manager creates a manual segment, adds and removes a customer', async ({ asRole, arrange, uniq }) => {
        const [segmentName, customerName] = [uniq('Segmen Manual'), uniq('Pelanggan')];
        const { customer_id } = arrange('crm.customer', { name: customerName });

        const page = await asRole(roleOf(roles, 'manager'));
        await page.goto('/dashboard/customer-segments');
        await page.getByRole('link', { name: 'Buat Segment' }).click();

        await page.getByRole('button', { name: 'Simpan Segment' }).click();
        await expect(page.getByText(/name field is required/i)).toBeVisible();

        await field(page, 'Nama Segment').fill(segmentName);
        await field(page, 'Deskripsi').fill('Dibuat oleh e2e');
        await page.getByRole('button', { name: 'Simpan Segment' }).click();

        await expect(page).toHaveURL(/customer-segments$/);
        await page.getByPlaceholder('Cari nama segment...').fill(segmentName);
        const row = page.getByRole('row').filter({ hasText: segmentName });
        await expect(row).toContainText('manual');
        await expect(row).toContainText('Aktif');

        await row.getByRole('link', { name: segmentName }).click();
        await expect(page.getByText('Belum ada anggota segment.')).toBeVisible();

        // Add the customer by hand: counted as a manual member.
        await page.locator('select').filter({ has: page.getByRole('option', { name: 'Pilih customer' }) }).selectOption(String(customer_id));
        await page.getByRole('button', { name: 'Tambahkan ke Segment' }).click();
        const member = page.getByRole('row').filter({ hasText: customerName });
        await expect(member).toContainText('manual');
        await expect(page.getByText('Total', { exact: true }).locator('xpath=following-sibling::p')).toHaveText('1');
        await expect(page.getByText('Manual', { exact: true }).locator('xpath=following-sibling::p')).toHaveText('1');

        // The customer page lists the segment too.
        await page.goto(`/dashboard/customers/${customer_id}`);
        await expect(page.getByText(segmentName).first()).toBeVisible();

        await page.goBack();
        await member.getByRole('button').click();
        await page.getByRole('button', { name: 'Ya, Hapus!' }).click();
        await expect(page.getByText('Belum ada anggota segment.')).toBeVisible();
    });
});
