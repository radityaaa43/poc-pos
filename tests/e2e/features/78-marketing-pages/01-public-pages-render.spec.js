import { test, expect } from '../../support/fixtures.js';

// Public marketing pages (routes/web.php): no login, one h1 each, all linked from the shared nav.
const pages = [
    { path: '/fitur', link: 'Fitur', h1: 'Fitur Lengkap untuk Bisnis Nyata' },
    { path: '/dokumentasi', link: 'Dokumentasi', h1: 'Dokumentasi Lengkap' },
    { path: '/roadmap', link: 'Roadmap', h1: 'Perjalanan Dikasir' },
    { path: '/kontribusi', link: 'Kontribusi', h1: 'Bantu Dikasir Tumbuh' },
];

for (const { path, h1 } of pages) {
    test(`${path} renders for a guest`, async ({ page }) => {
        const response = await page.goto(path);

        expect(response.status()).toBe(200);
        await expect(page).toHaveURL(new RegExp(`${path}$`));
        await expect(page.getByRole('heading', { level: 1, name: h1 })).toBeVisible();
    });
}

test('the roadmap lists releases and the nav reaches every page', async ({ page }) => {
    await page.goto('/roadmap');
    await expect(page.getByText('v2.0', { exact: true })).toBeVisible();
    await expect(page.getByText('Upgrade Laravel 12 → 13')).toBeVisible();

    const nav = page.getByRole('navigation').first();
    for (const { link, h1 } of pages) {
        await nav.getByRole('link', { name: link, exact: true }).click();
        await expect(page.getByRole('heading', { level: 1, name: h1 })).toBeVisible();
    }

    await nav.getByRole('link', { name: 'Masuk' }).click();
    await expect(page).toHaveURL(/\/login$/);
});
