#!/usr/bin/env node
/**
 * Static audit for the ThirdLine field/table conventions.
 *
 *   node tools/ui-audit.mjs resources/js/Pages/Tprm resources/js/Pages/TprmPortal
 *
 * Reports every <input>/<select>/<textarea> whose className is not one of the
 * shared classes (form-*, filter-*) or is an expression, every <table> that is
 * not `data-table`, every <th>/<td> still carrying its own padding, and every
 * <label> that styles itself. Exit code 1 when anything is listed, so it can
 * gate a phase. Layout tables (border-separate) and hidden/sr-only fields are
 * exempt by design, as is a <table data-table-exempt="reason"> (rendered rich
 * text, a compact dashboard widget). It gates a CHANGED SET, never the whole tree: the legacy
 * ERM screens carry hundreds of pre-adoption findings, so pass the files of
 * the diff under review.
 */
import fs from 'node:fs';
import path from 'node:path';

const roots = process.argv.slice(2).flatMap((a) => a.split(/\s+/)).filter(Boolean);
if (roots.length === 0) { console.error('usage: node tools/ui-audit.mjs <dir|file>...'); process.exit(2); }
const files = [];
const walk = (p) => { const st = fs.statSync(p); if (st.isDirectory()) fs.readdirSync(p).forEach((c) => walk(path.join(p, c))); else if (/\.jsx?$/.test(p)) files.push(p); };
roots.forEach(walk);

const TAGS = /<(input|select|textarea|table|th|td|label)\b/g;
const FIELD_OK = /^(form-(input|select|textarea|checkbox|radio)|filter-(input|select)|hidden|sr-only)$/;
const isCheckOrHidden = (t) => t === 'checkbox' || t === 'radio' || t === 'hidden';
// A file input's button is hatched on `file:*` in app.css, so it has nothing
// the baseline can defeat; the rest are not styled text fields at all.
const SKIP_TYPES = new Set(['file', 'hidden', 'range', 'submit', 'button', 'reset', 'color', 'image']);
const DEFEATED = /^(rounded(-\S+)?|shadow(-\S+)?|ring(-\S+)?|placeholder-\S+|resize(-\S+)?|focus:outline-\S+|border-(2|4|8|dashed|dotted|double)|border-[trblxy](-\S+)?|(hover|disabled|checked|active|group-[\w-]+|peer-[\w-]+):(bg-|border-|text-|shadow|rounded|ring-)\S*|focus:(bg-|text-|shadow)\S*|placeholder:\S+|focus-visible:(ring-|border-|outline-)\S*|read-only:\S+|appearance-\S+)$/;
const REDUNDANT = /^(border-gray-300|border-slate-300|border-gray-200|shadow-sm)$/;
const notes = [];
const TH_BAD = /^(p-\d|px-\d|py-\d|pb-\d|text-gray-\d00|font-medium|uppercase|tracking-wide)/;
const TD_BAD = /^(p-\d|px-\d|py-\d|pb-\d)$/;
const LABEL_OK = /^(form-label|sr-only|filter-label)$/;
const LABEL_WRAP = /^(flex|inline-flex|inline-block|block|cursor-pointer|group|relative)/;

function tagEnd(src, i) {
    let depth = 0, q = null;
    for (; i < src.length; i++) {
        const c = src[i];
        if (q) { if (c === '\\') { i++; continue; } if (c === q) q = null; continue; }
        if (depth > 0 && c === '/' && src[i + 1] === '/') { const nl = src.indexOf('\n', i); if (nl < 0) return -1; i = nl; continue; }
        if (depth > 0 && c === '/' && src[i + 1] === '*') { const ce = src.indexOf('*/', i + 2); if (ce < 0) return -1; i = ce + 1; continue; }
        if (c === '"' || c === "'" || c === '`') { q = c; continue; }
        if (c === '{') depth++;
        else if (c === '}') depth--;
        else if (c === '>' && depth === 0) return i;
    }
    return -1;
}
const lastMatch = (str, re) => { let i = -1, m; while ((m = re.exec(str))) i = m.index; return i; };
const lineOf = (src, i) => src.slice(0, i).split('\n').length;
const findings = [];
for (const f of files) {
    // Block comments are documentation, not markup: a JSDoc example such as
    // `<input className="form-input" />` must not be audited. Blank them to
    // spaces so every offset and line number stays true.
    const src = fs.readFileSync(f, 'utf8').replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '));
    const tables = [...src.matchAll(/<table\b[^>]*?className="([^"]*)"/g)].map((m) => m[1]);
    const layoutFile = tables.some((c) => /border-separate/.test(c));
    TAGS.lastIndex = 0; let m;
    while ((m = TAGS.exec(src))) {
        const start = m.index, tag = m[1], end = tagEnd(src, start); if (end < 0) { findings.push(`${f}:${lineOf(src, start)}  scanner could not find the end of <${tag}> — file not fully audited`); break; }
        const text = src.slice(start, end + 1); const at = `${f}:${lineOf(src, start)}`;
        const cm = text.match(/className="([^"]*)"/); const expr = /className=\{/.test(text);
        const tokens = cm ? cm[1].split(/\s+/).filter(Boolean) : [];
        const typeM = text.match(/type="([^"]*)"/); const type = typeM ? typeM[1] : 'text';
        if (tag === 'input' && (type === 'checkbox' || type === 'radio') && cm) {
            // The checkbox/radio block in app.css has no hatches at all.
            const dead = tokens.filter((t) => /^(rounded(-\S+)?|border-\S+|text-\S+|focus:\S+|shadow(-\S+)?|[wh]-\d\S*)$/.test(t));
            if (dead.length) findings.push(`${at}  <${tag} type=${type}> carries utilities the checkbox/radio baseline defeats (it has no hatches): "${dead.join(' ')}"`);
        }
        if (['input', 'select', 'textarea'].includes(tag)) {
            if (SKIP_TYPES.has(type) && type !== 'file') continue;
            const hasBg = tokens.some((t) => /^bg-/.test(t));
            // A variant background (`focus:bg-*`, `disabled:bg-*`) works when the element also
            // states a resting `bg-*`, because that hatches the baseline background rule out.
            const dead = (t) => (DEFEATED.test(t) && !(hasBg && /^[\w-]+:bg-/.test(t))) || (tag === 'select' && /^p[rx]-/.test(t));
            if (type !== 'file') {
                // A className expression is fine when the shared class arrives through a
                // literal (`className={\`form-input ${x}\`}`) or a file-local constant that
                // starts with it (DynamicForm's INPUT/SELECT), followed one hop at a time.
                const identOk = (name, depth = 0) => {
                    if (depth > 3) return false;
                    const def = src.match(new RegExp(`\\b(?:const|let)\\s+${name}\\s*=\\s*(.+)`));
                    if (!def) return false;
                    if (/^['"\`]form-/.test(def[1].trim())) return true;
                    const inner = def[1].trim().match(/^([A-Za-z_$][\w$]*)/);
                    return !!inner && inner[1] !== name && identOk(inner[1], depth + 1);
                };
                const exprIdent = expr && text.match(/className=\{\s*([A-Za-z_$][\w$]*)/);
                if (expr && (/className=\{\s*['"`]form-/.test(text) || (exprIdent && identOk(exprIdent[1])))) {
                    // The shared class is present; still check the literal parts of a
                    // template for utilities the baseline defeats.
                    const tpl = text.match(/className=\{\s*`([^`]*)`/);
                    const lit = tpl ? tpl[1].replace(/\$\{[^}]*\}/g, ' ').split(/\s+/).filter(Boolean) : [];
                    const deadLit = lit.filter((t) => dead(t) || REDUNDANT.test(t));
                    if (deadLit.length) findings.push(`${at}  <${tag}> template carries utilities the field baseline defeats: "${deadLit.join(' ')}"`);
                }
                else if (expr) findings.push(`${at}  <${tag}> className is an expression — resolve by hand`);
                else if (!tokens.some((t) => FIELD_OK.test(t))) findings.push(`${at}  <${tag}${typeM ? ` type=${type}` : ''}> lacks a shared class (has: "${cm ? cm[1] : ''}")`);
                else if (tokens.some(dead)) findings.push(`${at}  <${tag}> carries a utility the field baseline defeats (see the "NOT hatched" list in app.css): "${cm[1]}"`);
                else if (tokens.some((t) => REDUNDANT.test(t))) findings.push(`${at}  <${tag}> restates the baseline (redundant, drop it): "${cm[1]}"`);
            }
            // Accessible name: an id (for a label's htmlFor), aria-label(ledby),
            // a wrapping <label>, or a <FormField> parent (which wires the id
            // and the label itself). Anything else is a control a screen
            // reader announces as "edit text" with no name.
            const idM = text.match(/\bid="([^"]+)"/);
            const namedById = !!idM && src.includes(`htmlFor="${idM[1]}"`);
            const idExpr = text.match(/\bid=(\{[^}]*\})/);
            const namedByExpr = !!idExpr && src.includes(`htmlFor=${idExpr[1]}`);
            if (!isCheckOrHidden(type) && !tokens.includes('hidden') && !tokens.includes('sr-only') && !namedById && !namedByExpr && !/\baria-label(ledby)?=/.test(text) && !/\{\.\.\./.test(text)) {
                const before = src.slice(0, start);
                const wrappedByLabel = before.lastIndexOf('<label') > before.lastIndexOf('</label>');
                // FormField wires the label only to a native control that is its DIRECT child:
                // the wrapper's open tag must carry a label, must not be self-closing, and
                // only whitespace may sit between it and the control. A file-local <Field>
                // counts only when the file builds it on FormField.
                const directChildOf = (re) => {
                    const o = lastMatch(before, re);
                    if (o < 0) return false;
                    const e = tagEnd(src, o);
                    const openTag = src.slice(o, e + 1);
                    return /\blabel=/.test(openTag) && !/\/>$/.test(openTag) && /^\s*$/.test(src.slice(e + 1, start));
                };
                const insideFormField = directChildOf(/<FormField\b/g);
                const insideLocalField = /<FormField\b/.test(src) && directChildOf(/<Field[\s>]/g);
                if (!wrappedByLabel && !insideFormField && !insideLocalField) findings.push(`${at}  <${tag}${typeM ? ` type=${type}` : ''}> has no accessible name (no id/aria-label, not inside <label> or <FormField>)`);
            }
        } else if (tag === 'table') {
            if (layoutFile && cm && /border-separate/.test(cm[1])) continue;
            // A table that is not a data grid says so, with a reason a reviewer can read.
            const ex = text.match(/data-table-exempt="([^"]+)"/);
            if (ex) { notes.push(`${at}  <table> exempt: ${ex[1]}`); continue; }
            if (expr) findings.push(`${at}  <table> className is an expression — resolve by hand`);
            else if (!tokens.includes('data-table')) findings.push(`${at}  <table> is not data-table (has: "${cm ? cm[1] : ''}")`);
        } else if ((tag === 'th' || tag === 'td') && cm) {
            // Judged against the NEAREST preceding <table>, not the file: a
            // border-separate layout table elsewhere in the file exempts nothing here.
            const bad = tokens.filter((t) => (tag === 'th' ? TH_BAD : TD_BAD).test(t));
            const tOpen = lastMatch(src.slice(0, start), /<table\b/g);
            const tCls = tOpen >= 0 ? (src.slice(tOpen, tagEnd(src, tOpen) + 1).match(/className="([^"]*)"/) || [])[1] || '' : '';
            const inDataTable = tCls.split(/\s+/).includes('data-table');
            if (bad.length && inDataTable) findings.push(`${at}  <${tag}> styles itself inside a data-table: "${cm[1]}"`);
        } else if (tag === 'label' && cm) {
            if (tokens.some((t) => LABEL_OK.test(t))) continue;
            if (tokens.some((t) => LABEL_WRAP.test(t))) continue; // wraps a checkbox/radio or an inline control
            findings.push(`${at}  <label> styles itself: "${cm[1]}"`);
        }
    }
}
findings.forEach((l) => console.log(l));
if (notes.length) console.log('\nexempt tables (reported, not findings):\n' + notes.join('\n'));
console.log(`\n${findings.length} finding(s) across ${files.length} file(s)`);
process.exit(findings.length ? 1 : 0);
