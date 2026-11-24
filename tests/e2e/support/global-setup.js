import { artisan } from './cli.js';

// Every run starts from the same database; set E2E_SKIP_RESET=1 to keep the current one.
export default function globalSetup() {
    if (process.env.E2E_SKIP_RESET !== '1') {
        artisan(['e2e:reset']);
    }
}
