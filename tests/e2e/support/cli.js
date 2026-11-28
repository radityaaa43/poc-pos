// Runs `php artisan ...` for the e2e app: host `php` with e2e-env.js locally, plain `php` with the
// workflow's env in CI. Usable as a script: node cli.js e2e:reset
// E2E_PHP replaces both, for a second worktree's own app: "bash ../e2e-php-8011.sh" (see ../README.md).
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { e2eEnv } from './e2e-env.js';

export function artisan(args) {
    const override = process.env.E2E_PHP?.split(' ');
    const [cmd, ...head] = override ?? ['php'];
    const env = override || process.env.CI ? process.env : { ...process.env, ...e2eEnv };

    return execFileSync(cmd, [...head, 'artisan', ...args], { encoding: 'utf8', env, stdio: ['ignore', 'pipe', 'inherit'] });
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    process.stdout.write(artisan(process.argv.slice(2)));
}
