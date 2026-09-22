#!/usr/bin/env node
// Proves every rule in ui-audit.mjs against a fixture, so a weakened rule
// fails here before it lets a screen through the gate. `npm run ui:audit:test`.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ui-audit-'));
const tool = new URL('./ui-audit.mjs', import.meta.url).pathname;
const cases = [
    ['id_without_htmlFor', '<div><input id="x" className="form-input" /></div>', /has no accessible name/],
    ['id_with_htmlFor', '<div><label htmlFor="x">A</label><input id="x" className="form-input" /></div>', null],
    ['id_expr_mismatch', '<div><label htmlFor={y}>A</label><input id={x} className="form-input" /></div>', /has no accessible name/],
    ['id_expr_match', '<div><label htmlFor={x}>A</label><input id={x} className="form-input" /></div>', null],
    ['selfclosing_field', '<div><FormField label="A" /><select className="form-select" /></div>', /has no accessible name/],
    ['nested_in_div', '<FormField label="A"><div><select className="form-select" /></div></FormField>', /has no accessible name/],
    ['direct_child', '<FormField label="A"><select className="form-select" /></FormField>', null],
    ['defeated_rounded', '<input aria-label="a" className="form-input rounded-full" />', /baseline defeats/],
    ['defeated_ring', '<input aria-label="a" className="form-input ring-1" />', /baseline defeats/],
    ['defeated_placeholder', '<input aria-label="a" className="form-input placeholder-white/40" />', /baseline defeats/],
    ['defeated_variant', '<input aria-label="a" className="form-input disabled:bg-amber-50" />', /baseline defeats/],
    ['select_px', '<select aria-label="a" className="form-select px-2" />', /baseline defeats/],
    ['select_pl', '<select aria-label="a" className="form-select pl-2" />', null],
    ['checkbox_box_utils', '<input type="checkbox" className="form-checkbox rounded border-gray-300" />', /checkbox\/radio baseline defeats/],
    ['redundant', '<input aria-label="a" className="form-input border-gray-300" />', /restates the baseline/],
    ['expr_constant', "const INPUT = 'form-input w-full';\nconst inputClass = INPUT + ' x';\n<input aria-label=\"a\" className={inputClass} />", null],
    ['expr_template_dead', '<input aria-label="a" className={`form-input ${x} rounded-full`} />', /template carries/],
    ['th_nearest_table', '<table className="border-separate"><tr><td className="px-4">l</td></tr></table>\n<table className="data-table"><tr><th className="px-4">h</th></tr></table>', /styles itself inside a data-table/],
    ['layout_only', '<table className="border-separate"><tr><td className="px-4">l</td></tr></table>', null],
    ['exempt_table', '<table data-table-exempt="why" className="min-w-full"><tr><td className="px-2">l</td></tr></table>', null],
    ['lacks_shared_class', '<input aria-label="a" className="w-full border px-3" />', /lacks a shared class/],
    ['expression_by_hand', '<input aria-label="a" className={cls} />', /className is an expression/],
    ['table_not_data_table', '<table className="min-w-full"><tr><td>x</td></tr></table>', /is not data-table/],
    ['label_styles_itself', '<label className="text-sm font-medium text-gray-700">A</label>', /<label> styles itself/],
    ['file_unnamed', '<input type="file" className="block text-sm" />', /has no accessible name/],
    ['file_named', '<input type="file" aria-label="Import file" className="block text-sm" />', null],
    ['file_class_not_checked', '<input type="file" aria-label="a" className="block rounded-full" />', null],
    ['defeated_placeholder_variant', '<input aria-label="a" className="form-input placeholder:text-white/40" />', /baseline defeats/],
    ['variant_bg_with_resting_bg', '<input aria-label="a" className="form-input bg-gray-50 focus:bg-white" />', null],
    ['variant_bg_without_resting_bg', '<input aria-label="a" className="form-input focus:bg-white" />', /baseline defeats/],
    ['jsdoc_ignored', '/** <input className="rounded" /> */\n<input aria-label="a" className="form-input" />', null],
];
let failed = 0;
for (const [name, body, expect] of cases) {
    const f = path.join(dir, `${name}.jsx`);
    fs.writeFileSync(f, `export default function X() { return (<>${body}</>); }\n`);
    const out = spawnSync(process.execPath, [tool, f], { encoding: 'utf8' }).stdout;
    const lines = out.split('\n').filter((l) => l.startsWith(f) && !/<table> exempt:/.test(l));
    const ok = expect ? lines.some((l) => expect.test(l)) : lines.length === 0;
    if (!ok) { failed++; console.log(`FAIL ${name}\n${out}`); }
}
fs.rmSync(dir, { recursive: true, force: true });
console.log(failed ? `${failed} self-test(s) failed` : `${cases.length} self-tests passed`);
process.exit(failed ? 1 : 0);
