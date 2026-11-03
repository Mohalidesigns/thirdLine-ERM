#!/usr/bin/env node
/**
 * Runs resources/js/lib/kri-band-truth-table.json through resources/js/lib/kri-band.js.
 * Exit 0 when every case agrees, 1 otherwise. tests/Unit/Kri/KriBandRuleTest runs the
 * same cases through PHP (ADR 0026 6.1); this is the node half.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { bandFor, ruleFromBoundaries } from '../resources/js/lib/kri-band.js';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const table = JSON.parse(readFileSync(join(root, 'resources/js/lib/kri-band-truth-table.json'), 'utf8'));
let failed = 0;

for (const c of table.cases) {
    const got = bandFor(ruleFromBoundaries(c.direction, c.green, c.red), c.value);

    if (got !== c.band) {
        failed += 1;
        console.error(`FAIL ${c.id}: expected ${c.band}, got ${got}`);
    }
}

console.log(`${table.cases.length - failed}/${table.cases.length} cases agree`);
process.exit(failed === 0 ? 0 : 1);
