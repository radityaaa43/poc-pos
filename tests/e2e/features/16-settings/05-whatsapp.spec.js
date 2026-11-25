import { test, expect as baseExpect } from '../../support/fixtures.js';
import { signIn } from '../../support/platform.js';

// Pages here are slow to answer on a shared machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 45_000 });
test.use({ actionTimeout: 60_000, navigationTimeout: 60_000 });

// The gateway is faked by E2eServiceProvider (wa.e2e.test); a real WhatsApp pairing is a manual check.
test.describe('whatsapp settings', () => {
    test.setTimeout(240_000);

    test('the automatic-send switches save and show again, and a test message goes out', async ({ page, arrange }) => {
        const admin = arrange('platform.user', { role: 'super-admin' });
        const reminder = page.getByLabel('Kirim reminder piutang otomatis');
        const invoice = page.getByLabel('Kirim invoice setelah transaksi');
        const save = async () => {
            const saved = page.waitForResponse((response) => response.url().endsWith('/settings/whatsapp') && response.request().method() === 'POST');
            await page.getByRole('button', { name: 'Simpan Pengaturan' }).click();
            await saved;
        };

        await signIn(page, admin.email);
        await page.goto('/dashboard/settings/whatsapp');
        await expect(page.getByPlaceholder('http://localhost:3001')).toHaveValue('http://wa.e2e.test');
        await expect(page.getByText(/^Terhubung/)).toBeVisible();
        const original = { reminder: await reminder.isChecked(), invoice: await invoice.isChecked() };

        try {
            await reminder.setChecked(!original.reminder);
            await invoice.setChecked(!original.invoice);
            await save();

            await page.reload();
            await expect(reminder).toBeChecked({ checked: !original.reminder });
            await expect(invoice).toBeChecked({ checked: !original.invoice });

            // The test message reaches the (fake) gateway.
            const sent = page.waitForResponse((response) => response.url().endsWith('/settings/whatsapp/test'));
            await page.getByPlaceholder('0812xxxxxxx').fill('081234567890');
            await page.getByRole('button', { name: 'Kirim', exact: true }).click();
            expect(await (await sent).json()).toEqual({ status: true });
        } finally {
            await page.goto('/dashboard/settings/whatsapp');
            await reminder.setChecked(original.reminder);
            await invoice.setChecked(original.invoice);
            await save();
        }
    });
});
