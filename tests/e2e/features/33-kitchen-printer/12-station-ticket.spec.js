import { test, expect } from '../../support/fixtures.js';
import { roles } from '../../support/roles.js';
import { setUpPrintDevice } from '../../support/kitchen.js';
import { fakePrinter, sentToPrinter, asText } from '../../support/webusb.js';

const cashier = roles.find((role) => role.key === 'cashier');

// A print device on its own page/context, serving one station (or every station when no label is given).
async function device(asRole, uniq, name, stationLabel) {
    const page = await asRole(cashier);
    await fakePrinter(page);
    const panel = await setUpPrintDevice(page, uniq(name));

    if (stationLabel) {
        await panel.getByRole('checkbox', { name: stationLabel }).check();
        await expect(panel.getByRole('checkbox', { name: stationLabel })).toBeChecked();
    }

    return page;
}

// The one ticket this device sent that carries the sale's number, as text.
async function ticket(page, invoice) {
    await expect
        .poll(async () => (await sentToPrinter(page)).map(asText).find((text) => text.includes(invoice)), { timeout: 15_000 })
        .toBeTruthy();

    return (await sentToPrinter(page)).map(asText).find((text) => text.includes(invoice));
}

// REQ-006 AC-07: a device prints only the items of the stations it serves, under a station-name title.
test.describe('33-kitchen-printer: station tickets', () => {
    test.describe.configure({ timeout: 240_000 });

    test('a two-station order prints one titled ticket per device, only its station', { tag: ['@REQ-006-AC-07'] }, async ({ asRole, arrange, uniq }) => {
        arrange('open-shift', { cash: 100000 });
        const bar = uniq('Bar');
        arrange('kitchen.station', { name: bar });

        // Each device serves one station; a device with no station set would get the whole order.
        const barDevice = await device(asRole, uniq, 'BarDev', bar);
        const dapurDevice = await device(asRole, uniq, 'DapurDev', 'Dapur');

        const sale = arrange('kitchen-sale', { prefix: uniq('Pisah'), flagged: 2, station: bar });
        const [dapurItem, barItem] = sale.flagged; // the last flagged product is the Bar one

        const barTicket = await ticket(barDevice, sale.invoice);
        expect(barTicket).toContain(`\n${bar.toUpperCase()}\n`); // the station name as the ticket title, in capitals
        expect(barTicket).toContain(barItem);
        expect(barTicket).not.toContain(dapurItem);
        expect(barTicket).not.toContain('DAPUR');

        const dapurTicket = await ticket(dapurDevice, sale.invoice);
        expect(dapurTicket).toContain('DAPUR');
        expect(dapurTicket).toContain(dapurItem);
        expect(dapurTicket).not.toContain(barItem);
    });
});
