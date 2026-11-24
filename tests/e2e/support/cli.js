// Runs `php artisan ...` where the e2e app lives: inside the `app-e2e` container locally,
// plain `php` in CI (the app runs on the runner). Usable as a script: node cli.js e2e:reset
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

export function artisan(args) {
    const [cmd, ...head] = process.env.CI
        ? ['php']
        : ['docker', 'compose', 'exec', '-T', 'app-e2e', 'php'];

    return execFileSync(cmd, [...head, 'artisan', ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'] });
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    process.stdout.write(artisan(process.argv.slice(2)));
}
