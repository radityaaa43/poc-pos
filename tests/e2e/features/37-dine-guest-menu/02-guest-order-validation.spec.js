import { test, expect } from '../../support/fixtures.js';

// Each cause leaves the guest on the menu with no order made. `attempt` drives the guest into
// the cause and returns the message the guest should read.
const causes = {
    'an emptied cart': async ({ page }) => {
        await page.getByRole('dialog').getByRole('button', { name: '−', exact: true }).click(); // remove the only item
        await page.getByRole('dialog').getByRole('button', { name: 'Pesan', exact: true }).click();

        return 'Pilih minimal satu item.';
    },
    'a note over 500 characters': async ({ page }) => {
        await page.getByRole('dialog').getByPlaceholder('Catatan pesanan (opsional)').fill('x'.repeat(501));
        await page.getByRole('dialog').getByRole('button', { name: 'Pesan', exact: true }).click();

        return 'Gagal mengirim pesanan.';
    },
    'self-ordering switched off': async ({ page, arrange, title }) => {
        try {
            arrange('dine.self-order', { enabled: '0' });
            await page.reload(); // the setting reaches the page as a prop; the cart starts empty again
            await addToCart(page, title);
            await page.getByRole('dialog').getByRole('button', { name: 'Pesan', exact: true }).click();
        } finally {
            arrange('dine.self-order', { enabled: '1' });
        }

        return 'Fitur pemesanan sementara nonaktif.';
    },
    'stock gone before the guest sends': async ({ page, arrange, product_id }) => {
        arrange('dine.set-stock', { product_id, stock: 0 }); // someone else bought the last ones
        await page.getByRole('dialog').getByRole('button', { name: 'Pesan', exact: true }).click();

        return /tidak mencukupi/;
    },
};

async function addToCart(page, title) {
    const card = page.locator('div.rounded-xl', { has: page.getByRole('heading', { name: title }) });
    await card.getByRole('button', { name: '+ Tambah' }).click();
    await page.getByRole('button', { name: /1 item/ }).click();
}

async function startOrder({ page, arrange, uniq }) {
    const { table_name, token } = arrange('dine.table', { area: uniq('Area'), name: uniq('M') });
    const title = uniq('Menu');
    const { product_id } = arrange('product', { title, stock: 5 });

    await page.goto(`/dine/${token}`);
    await addToCart(page, title);

    return { page, arrange, table_name, token, title, product_id };
}

test.describe('37-dine-guest-menu', () => {
    for (const [cause, attempt] of Object.entries(causes)) {
        test(`${cause}: no order is made and the guest stays on the menu`, async ({ page, arrange, uniq }) => {
            const ctx = await startOrder({ page, arrange, uniq });
            await attempt(ctx);

            // Let the request settle: the menu would redirect to the status page on success.
            await page.waitForLoadState('networkidle');
            await expect(page).toHaveURL(new RegExp(`/dine/${ctx.token}$`));
            expect(arrange('dine.table-state', { name: ctx.table_name }).orders).toBe(0);
        });

        test(`${cause}: the guest is told why`, async ({ page, arrange, uniq }) => {
            const ctx = await startOrder({ page, arrange, uniq });
            await expect(page.getByText(await attempt(ctx))).toBeVisible();
        });
    }

    test('an unknown table link is a 404', async ({ page }) => {
        const response = await page.goto('/dine/00000000-0000-4000-8000-000000000000');
        expect(response.status()).toBe(404);
    });
});
