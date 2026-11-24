import assert from 'node:assert/strict';
import { cpSync, existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { scaffold } from './new-feature.js';

const e2eDir = join(dirname(fileURLToPath(import.meta.url)), '..');

// Work on a throwaway copy so the real features/ and COVERAGE.md stay untouched.
function sandbox() {
    const dir = mkdtempSync(join(tmpdir(), 'e2e-new-'));
    cpSync(join(e2eDir, 'COVERAGE.md'), join(dir, 'COVERAGE.md'));

    return dir;
}

test('creates the step file from the template and appends a coverage row', () => {
    const dir = sandbox();
    try {
        const file = scaffold(dir, '08-purchase-orders', 'create-order');

        assert.equal(file, join(dir, 'features', '08-purchase-orders', '01-create-order.spec.js'));
        assert.match(readFileSync(file, 'utf8'), /test\.describe\('08-purchase-orders'/);
        assert.match(readFileSync(file, 'utf8'), /test\.fixme\('create-order:/);
        assert.match(readFileSync(join(dir, 'COVERAGE.md'), 'utf8'), /\| `08-purchase-orders` \| `01-create-order`/);
    } finally {
        rmSync(dir, { recursive: true });
    }
});

test('refuses bad slugs and an existing feature', () => {
    const dir = sandbox();
    try {
        for (const bad of ['purchase-orders', '8-po', '08-Purchase', '08_po', '08-']) {
            assert.throws(() => scaffold(dir, bad), /NN-kebab-case/, bad);
        }
        assert.throws(() => scaffold(dir), /Usage: npm run e2e:new/);
        assert.throws(() => scaffold(dir, ''), /Usage: npm run e2e:new/);
        assert.throws(() => scaffold(dir, '08-po', 'Bad Step'), /Step slug/);

        scaffold(dir, '08-po');
        assert.ok(existsSync(join(dir, 'features', '08-po', '01-happy-path.spec.js')));
        assert.throws(() => scaffold(dir, '08-po'), /already exists/);
    } finally {
        rmSync(dir, { recursive: true });
    }
});
