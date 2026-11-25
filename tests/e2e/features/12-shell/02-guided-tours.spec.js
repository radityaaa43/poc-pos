import { test, expect as baseExpect } from '../../support/fixtures.js';
import { signIn } from '../../support/platform.js';

// Pages here are slow to answer on a shared machine: wait longer than the 15 s default.
const expect = baseExpect.configure({ timeout: 45_000 });
test.use({ actionTimeout: 60_000, navigationTimeout: 60_000 });

// The seeded users have every tour done (otherwise the overlay blocks clicks); this user has none.
test.describe('guided tours', () => {
    test.setTimeout(240_000);

    test('a new user is guided through the dashboard once, and can replay the tour', async ({ page, arrange }) => {
        const { email } = arrange('platform.user', { name: 'Zeta Tour', tours: 'none' });
        const tour = page.locator('.driver-popover');
        const completed = () => arrange('platform.user-state', { email }).completed_tours ?? [];
        // Closing a tour saves it as done; wait for that save, or a late reload can start the tour again.
        const closeTour = async () => {
            const saved = page.waitForResponse((response) => response.url().includes('/tours/dashboard/complete'));
            await tour.getByRole('button', { name: 'Close' }).click();
            await saved;
            await expect(page.locator('body')).not.toHaveClass(/driver-active/);
        };

        await signIn(page, email);
        await page.goto('/dashboard');

        // The tour starts by itself and walks the cards step by step.
        await expect(tour.getByText('Halaman Dashboard')).toBeVisible();
        await tour.getByRole('button', { name: /Next/ }).click();
        await expect(tour.getByText('Kartu Statistik')).toBeVisible();
        await tour.getByRole('button', { name: /Previous/ }).click();
        await expect(tour.getByText('Halaman Dashboard')).toBeVisible();

        // Closing it counts as done, so it does not start again by itself.
        expect(completed()).not.toContain('dashboard');
        await closeTour();
        expect(completed()).toContain('dashboard');

        // The "Panduan Penggunaan" button starts it on demand.
        await page.getByRole('button', { name: 'Panduan Penggunaan' }).click();
        await expect(tour.getByText('Halaman Dashboard')).toBeVisible();
        await closeTour();

        // "Putar Ulang Panduan" in the user menu forgets every completed tour, so it starts again.
        await page.getByRole('button', { name: 'Z', exact: true }).click();
        await page.getByRole('menuitem', { name: 'Putar Ulang Panduan' }).click();
        await expect(tour.getByText('Halaman Dashboard')).toBeVisible();
    });
});
