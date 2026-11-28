// `npm run e2e:up` starts the local e2e app on host PHP and host MySQL; `--stop` (`e2e:down`) stops it.
import { execFileSync, spawn } from 'node:child_process';
import { existsSync, openSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { connect } from 'node:net';
import { setTimeout as sleep } from 'node:timers/promises';
import { e2eEnv } from './e2e-env.js';

const { port } = new URL(e2eEnv.APP_URL);
const pidFile = 'storage/logs/e2e-serve.pid';
const logFile = 'storage/logs/e2e-serve.log';

const listening = () =>
    new Promise((resolve) => {
        const socket = connect(Number(port), '127.0.0.1', () => {
            socket.destroy();
            resolve(true);
        });
        socket.on('error', () => resolve(false));
    });

// The whole tree: `artisan serve` runs `php -S` as a child process.
function kill(pid) {
    if (process.platform === 'win32') {
        execFileSync('taskkill', ['/PID', String(pid), '/T', '/F'], { stdio: 'inherit' });
    } else {
        process.kill(-pid);
    }
}

async function up() {
    // Otherwise the suite would run against whatever already answers, possibly another checkout's code.
    if (await listening()) {
        throw new Error(`Port ${port} is busy: run \`npm run e2e:down\` in the checkout that started it.`);
    }

    execFileSync('mysql', [
        `--host=${e2eEnv.DB_HOST}`,
        `--port=${e2eEnv.DB_PORT}`,
        `--user=${e2eEnv.DB_USERNAME}`,
        `--execute=CREATE DATABASE IF NOT EXISTS ${e2eEnv.DB_DATABASE} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`,
    ], { stdio: 'inherit' });

    const log = openSync(logFile, 'w');
    // --no-reload: without it `artisan serve` drops these env vars and reads .env, the dev database.
    const server = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`, '--no-reload'], {
        env: { ...process.env, ...e2eEnv },
        detached: true,
        windowsHide: true,
        stdio: ['ignore', log, log],
    });
    server.unref();
    writeFileSync(pidFile, String(server.pid));

    for (let attempt = 0; attempt < 60 && server.exitCode === null; attempt++) {
        await sleep(500);
        if (await listening()) {
            console.log(`e2e app: ${e2eEnv.APP_URL} (database ${e2eEnv.DB_DATABASE}, log ${logFile})`);
            return;
        }
    }

    if (server.exitCode === null) kill(server.pid);
    throw new Error(`Nothing answers on :${port}; see ${logFile}.`);
}

async function down() {
    if (!existsSync(pidFile)) {
        console.log('No e2e server was started from this checkout.');
        return;
    }

    try {
        // Only while the port answers, so a stale pid file does not kill a reused pid.
        if (await listening()) kill(Number(readFileSync(pidFile, 'utf8')));
    } finally {
        rmSync(pidFile);
    }
}

await (process.argv.includes('--stop') ? down() : up());
