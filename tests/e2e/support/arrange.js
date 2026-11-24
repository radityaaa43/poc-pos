import { artisan } from './cli.js';

/**
 * Creates data for a step through `php artisan e2e:arrange` and returns the created ids.
 * Scenarios live in app/Testing/E2e/Scenarios.php.
 */
export function arrange(scenario, options = {}) {
    const args = Object.entries(options).map(([key, value]) => `--arg=${key}=${value}`);

    return JSON.parse(artisan(['e2e:arrange', scenario, ...args]).trim().split('\n').pop());
}
