import { test, expect } from '../../support/fixtures.js';
import { role } from '../../support/inventory.js';

const chips = (page) => page.getByRole('group', { name: 'Stasiun' });
const chip = (page, name) => chips(page).getByRole('button', { name, exact: true });
const order = (page, invoice) => page.locator('article', { hasText: invoice });
const lines = (page, invoice) => order(page, invoice).getByRole('listitem');

// Opens the kitchen display on a new device (a new browser context) and taps the station chip, as a tablet is set up once.
async function device(asRole, station) {
    const page = await asRole(role('cashier'));
    await page.goto('/dashboard/kitchen');
    if (station) {
        await chip(page, station).click();
        await expect(chip(page, station)).toHaveAttribute('aria-pressed', 'true');
    }

    return page;
}

// REQ-006 AC-05: a display set to a station shows only its items; the choice is kept per device.
test.describe('34-kitchen-stations: device filter', () => {
    test.describe.configure({ timeout: 120_000 });

    test('@REQ-006-AC-05 a Bar display shows only the Bar item and Siap there leaves the Dapur item alone', async ({ asRole, arrange, uniq }) => {
        const bar = uniq('Bar');
        const sale = arrange('kitchen-sale', { prefix: uniq('Pisah'), flagged: 2, station: bar });
        const [dapurItem, barItem] = sale.flagged; // the last flagged product is the Bar one

        const barDevice = await device(asRole, bar);
        await expect(lines(barDevice, sale.invoice)).toHaveCount(1);
        const drink = lines(barDevice, sale.invoice).filter({ hasText: barItem });
        await expect(drink).toBeVisible();

        await drink.getByRole('button', { name: 'Mulai buat', exact: true }).click();
        await expect(drink).toContainText('Sedang dibuat');
        await drink.getByRole('button', { name: 'Siap', exact: true }).click();
        // Ready: no step left to make, only the hand-over (Serahkan, #238) is offered to a role that has it.
        await expect(drink.getByRole('button', { name: /^(Mulai buat|Siap)$/ })).toHaveCount(0);
        await expect(drink.getByRole('button', { name: 'Serahkan', exact: true })).toBeVisible();
        expect(arrange('kitchen.status-by-station', { invoice: sale.invoice }).status_by_station).toEqual({ [bar]: 'ready', Dapur: 'accepted' });

        // The choice is saved on the device: a reload keeps Bar.
        await barDevice.reload();
        await expect(chip(barDevice, bar)).toHaveAttribute('aria-pressed', 'true');
        await expect(lines(barDevice, sale.invoice)).toHaveCount(1);

        // Another device starts on Semua stasiun and shows both items; a Dapur device shows only the Dapur one.
        const fresh = await device(asRole);
        await expect(lines(fresh, sale.invoice)).toHaveCount(2);
        const dapurDevice = await device(asRole, 'Dapur');
        await expect(lines(dapurDevice, sale.invoice)).toHaveCount(1);
        await expect(lines(dapurDevice, sale.invoice)).toContainText(dapurItem);
    });

    test('@REQ-006-AC-05 an item of a deleted station shows on a Dapur display with its old name, and not on a Bar display', async ({ asRole, arrange, uniq }) => {
        const bar = uniq('Bar');
        const gone = uniq('Sementara');
        arrange('kitchen-sale', { prefix: uniq('Bar'), flagged: 1, station: bar }); // so Bar is an active station with its own chip
        const sale = arrange('kitchen-sale', { prefix: uniq('Hapus'), flagged: 1, station: gone });
        arrange('kitchen.station-delete', { name: gone });

        // Work from a removed station goes to Dapur: nothing is ever orphaned in the queue.
        const dapurDevice = await device(asRole, 'Dapur');
        await expect(lines(dapurDevice, sale.invoice)).toHaveCount(1);
        await expect(lines(dapurDevice, sale.invoice)).toContainText(gone); // still labelled with the old station name

        const barDevice = await device(asRole, bar);
        await expect(order(barDevice, sale.invoice)).toHaveCount(0);

        // The item keeps its old station name on Semua stasiun too, and in the data.
        const all = await device(asRole);
        await expect(lines(all, sale.invoice)).toContainText(gone);
        expect(arrange('kitchen.item-stations', { invoice: sale.invoice })).toEqual({ station_names: [gone] });
    });
});
