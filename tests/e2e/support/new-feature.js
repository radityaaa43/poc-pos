// npm run e2e:new -- <NN-feature-slug> [first-step-slug]
// Scaffolds tests/e2e/features/<feature>/01-<step>.spec.js and adds a row to COVERAGE.md.
import { appendFileSync, existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const SLUG = /^\d{2}-[a-z0-9]+(-[a-z0-9]+)*$/;
const STEP = /^[a-z0-9]+(-[a-z0-9]+)*$/;

export function scaffold(e2eDir, feature, step = 'happy-path') {
    if (!feature) {
        throw new Error('Usage: npm run e2e:new -- <NN-feature-slug> [first-step-slug], e.g. npm run e2e:new -- 08-purchase-orders create-order');
    }
    if (!SLUG.test(feature)) {
        throw new Error(`Feature slug must look like 08-purchase-orders (NN-kebab-case), got '${feature}'.`);
    }
    if (!STEP.test(step)) {
        throw new Error(`Step slug must be kebab-case, got '${step}'.`);
    }

    const dir = join(e2eDir, 'features', feature);
    if (existsSync(dir)) {
        throw new Error(`features/${feature} already exists: add a step file there instead.`);
    }

    const file = join(dir, `01-${step}.spec.js`);
    const template = readFileSync(join(here, 'templates', 'step.spec.js'), 'utf8');
    mkdirSync(dir, { recursive: true });
    writeFileSync(file, template.replaceAll('__FEATURE__', feature).replaceAll('__STEP__', step));
    appendFileSync(join(e2eDir, 'COVERAGE.md'), `| \`${feature}\` | \`01-${step}\`: TODO | TODO |\n`);

    return file;
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    try {
        const file = scaffold(join(here, '..'), process.argv[2], process.argv[3]);
        console.log(`Created ${file}\nReplace the test.fixme, fill in the COVERAGE.md row, then run npm run test:e2e.`);
    } catch (error) {
        console.error(error.message);
        process.exit(1);
    }
}
