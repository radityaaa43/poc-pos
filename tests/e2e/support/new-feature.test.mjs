import assert from 'node:assert/strict';
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { scaffold } from './new-feature.js';

// Work in a throwaway dir so the real features/ stay untouched.
const sandbox = () => mkdtempSync(join(tmpdir(), 'e2e-new-'));

test('creates the step file from the template and the folder coverage note', () => {
    const dir = sandbox();
    try {
        const file = scaffold(dir, '08-purchase-orders', 'create-order');

        assert.equal(file, join(dir, 'features', '08-purchase-orders', '01-create-order.spec.js'));
        assert.match(readFileSync(file, 'utf8'), /test\.describe\('08-purchase-orders'/);
        assert.match(readFileSync(file, 'utf8'), /test\.fixme\('create-order:/);
        assert.match(readFileSync(file, 'utf8'), /\{ tag: \[\] \}/);
        assert.match(readFileSync(join(dir, 'features', '08-purchase-orders', 'COVERAGE.md'), 'utf8'), /^# 08-purchase-orders\n[\s\S]*\| `01-create-order`: TODO \|/);
        assert.ok(!existsSync(join(dir, 'COVERAGE.md')), 'no shared coverage file');
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
