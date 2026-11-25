import { test, expect as baseExpect } from '../../support/fixtures.js';
import { field, signIn } from '../../support/platform.js';

// Pages here are slow to answer on a shared machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 45_000 });
test.use({ actionTimeout: 60_000, navigationTimeout: 60_000 });

// Both pages feed checkout (auto print, loyalty points): each step puts back what it found.
test.describe('printer and loyalty settings', () => {
    test.setTimeout(240_000);

    test('the printer settings save and show again (the USB printer itself is a manual check)', async ({ page, arrange }) => {
        const { email } = arrange('platform.user');
        const paper = page.getByRole('main').getByRole('combobox');
        const auto = page.getByLabel('Cetak otomatis setelah transaksi');
        const save = async () => {
            const saved = page.waitForResponse((response) => response.url().endsWith('/settings/printer') && response.request().method() === 'POST');
            await page.getByRole('button', { name: 'Simpan Pengaturan' }).click();
            await saved;
        };

        await signIn(page, email);
        await page.goto('/dashboard/settings/printer');
        const original = { paper: await paper.inputValue(), auto: await auto.isChecked() };

        try {
            await paper.selectOption('58mm');
            await auto.setChecked(!original.auto);
            await save();

            await page.reload();
            await expect(paper).toHaveValue('58mm');
            await expect(auto).toBeChecked({ checked: !original.auto });
        } finally {
            await page.goto('/dashboard/settings/printer');
            await paper.selectOption(original.paper);
            await auto.setChecked(original.auto);
            await save();
        }
    });

    test('the loyalty rates and tier thresholds save and show again', async ({ page, arrange }) => {
        const { email } = arrange('platform.user');
        const earnRate = field(page, 'Nominal belanja untuk 1 poin');
        const redeemValue = field(page, 'Nilai rupiah per 1 poin redeem');
        const silver = field(page, 'Silver');
        const earn = page.getByLabel('Aktifkan earn points');
        const save = async () => {
            const saved = page.waitForResponse((response) => response.url().endsWith('/settings/loyalty') && response.request().method() === 'POST');
            await page.getByRole('button', { name: 'Simpan Pengaturan' }).click();
            await saved;
        };

        await signIn(page, email);
        await page.goto('/dashboard/settings/loyalty');
        const original = { earnRate: await earnRate.inputValue(), redeemValue: await redeemValue.inputValue(), silver: await silver.inputValue(), earn: await earn.isChecked() };

        try {
            // The earn rate must be at least 1.
            await earnRate.fill('0');
            await save();
            await expect(page.getByText('The earn rate amount field must be at least 1.')).toBeVisible();

            await earnRate.fill('25000');
            await redeemValue.fill('250');
            await silver.fill('750000');
            await earn.setChecked(!original.earn);
            await save();

            await page.reload();
            await expect(earnRate).toHaveValue('25000');
            await expect(redeemValue).toHaveValue('250');
            await expect(silver).toHaveValue('750000');
            await expect(earn).toBeChecked({ checked: !original.earn });
        } finally {
            await page.goto('/dashboard/settings/loyalty');
            await earnRate.fill(original.earnRate);
            await redeemValue.fill(original.redeemValue);
            await silver.fill(original.silver);
            await earn.setChecked(original.earn);
            await save();
        }
    });
});
