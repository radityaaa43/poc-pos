import { test, expect as baseExpect } from '../../support/fixtures.js';
import { field, signIn } from '../../support/platform.js';

// Pages here are slow to answer on a shared machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 45_000 });
test.use({ actionTimeout: 60_000, navigationTimeout: 60_000 });

// The store profile is global (receipts, the sidebar, the default tax rate): the step puts the
// values it found back in the end, so other groups still see the seeded store.
test.describe('store profile and tax', () => {
    test.setTimeout(240_000);

    test('the store profile saves, shows again after a reload, and sets the default tax rate', async ({ page, arrange, uniq }) => {
        const { email } = arrange('platform.user');
        const name = uniq('Toko Uji');
        const inputs = {
            name: page.getByPlaceholder('Nama toko'),
            address: field(page, 'Alamat Lengkap'),
            city: page.getByPlaceholder('contoh: Surabaya'),
            phone: page.getByPlaceholder('0812xxxxxxx'),
            email: page.getByPlaceholder('email@toko.com'),
            website: page.getByPlaceholder('https://'),
            npwp: page.getByPlaceholder('XX.XXX.XXX.X-XXX.XXX'),
            nib: page.getByPlaceholder('Nomor Induk Berusaha'),
            tax: page.getByRole('spinbutton'),
        };
        const save = () => page.getByRole('button', { name: 'Simpan Profil' }).click();

        await signIn(page, email);
        await page.goto('/dashboard/settings/store');
        await expect(inputs.name).toBeVisible();
        const original = {};
        for (const [key, input] of Object.entries(inputs)) original[key] = await input.inputValue();

        try {
            // The name is required (the browser itself guards the address and email fields).
            await inputs.name.fill('');
            await save();
            await expect(page.getByText('The store name field is required.')).toBeVisible();

            await inputs.name.fill(name);
            await inputs.address.fill('Jl. Contoh No. 1');
            await inputs.city.fill('Surabaya');
            await inputs.phone.fill('081234567890');
            await inputs.email.fill('toko@e2e.test');
            await inputs.website.fill('https://toko.e2e.test');
            await inputs.npwp.fill('01.234.567.8-901.000');
            await inputs.nib.fill('1234567890123');
            await inputs.tax.fill('12.5');
            await save();

            await page.reload();
            await expect(inputs.name).toHaveValue(name);
            await expect(inputs.address).toHaveValue('Jl. Contoh No. 1');
            await expect(inputs.city).toHaveValue('Surabaya');
            await expect(inputs.phone).toHaveValue('081234567890');
            await expect(inputs.email).toHaveValue('toko@e2e.test');
            await expect(inputs.website).toHaveValue('https://toko.e2e.test');
            await expect(inputs.npwp).toHaveValue('01.234.567.8-901.000');
            await expect(inputs.nib).toHaveValue('1234567890123');
            await expect(inputs.tax).toHaveValue('12.50');

            // The name shows in the sidebar, and a new product starts with the new tax rate.
            await expect(page.getByText(name).first()).toBeVisible();
            await page.goto('/dashboard/products/create');
            await expect(page.getByLabel('Persentase Pajak (%)')).toHaveValue('12.5');
        } finally {
            await page.goto('/dashboard/settings/store');
            await inputs.name.fill(original.name);
            await inputs.address.fill(original.address);
            await inputs.city.fill(original.city);
            await inputs.phone.fill(original.phone);
            await inputs.email.fill(original.email);
            await inputs.website.fill(original.website);
            await inputs.npwp.fill(original.npwp);
            await inputs.nib.fill(original.nib);
            await inputs.tax.fill(original.tax);
            await save();
            await expect(page.getByText(original.name).first()).toBeVisible();
        }
    });
});
