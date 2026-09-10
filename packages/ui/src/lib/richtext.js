// Shared helpers for Editor.js content. Rich-text fields store the Editor.js
// output document as a JSON string ({ time, blocks, version }); legacy rows
// hold plain text. Everything that touches such a field goes through here so
// the two shapes stay interchangeable.

export function isEditorJsData(value) {
    return !!value && typeof value === 'object' && Array.isArray(value.blocks);
}

/**
 * Parse a stored field value into Editor.js data, or null when the value is
 * legacy plain text (or empty).
 */
export function parseEditorJs(value) {
    if (!value) return null;
    if (isEditorJsData(value)) return value;
    if (typeof value !== 'string') return null;
    const trimmed = value.trim();
    if (!trimmed.startsWith('{')) return null;
    try {
        const parsed = JSON.parse(trimmed);
        return isEditorJsData(parsed) ? parsed : null;
    } catch {
        return null;
    }
}

function escapeHtml(text) {
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

/**
 * Wrap plain text into Editor.js blocks. Consecutive "• " / "- " lines become
 * an unordered list and "1. " lines an ordered list (the shapes emitted by
 * ReportSectionNormalizer and the AI writer); everything else becomes
 * paragraphs split on blank lines.
 */
export function textToEditorJs(text) {
    const blocks = [];
    const paragraphs = String(text).replace(/\r\n/g, '\n').split(/\n{2,}/);

    for (const para of paragraphs) {
        const lines = para.split('\n').filter((l) => l.trim() !== '');
        if (!lines.length) continue;

        let buffer = [];
        const flushBuffer = () => {
            if (buffer.length) {
                blocks.push({ type: 'paragraph', data: { text: buffer.map(escapeHtml).join('<br>') } });
                buffer = [];
            }
        };

        let i = 0;
        while (i < lines.length) {
            const bullet = lines[i].match(/^\s*(?:•|-)\s+(.*)/);
            const ordered = lines[i].match(/^\s*\d+[.)]\s+(.*)/);
            if (bullet || ordered) {
                flushBuffer();
                const style = bullet ? 'unordered' : 'ordered';
                const matcher = bullet ? /^\s*(?:•|-)\s+(.*)/ : /^\s*\d+[.)]\s+(.*)/;
                const items = [];
                while (i < lines.length) {
                    const m = lines[i].match(matcher);
                    if (!m) break;
                    items.push({ content: escapeHtml(m[1]), meta: {}, items: [] });
                    i++;
                }
                blocks.push({ type: 'list', data: { style, items } });
            } else {
                buffer.push(lines[i]);
                i++;
            }
        }
        flushBuffer();
    }

    return { time: Date.now(), blocks, version: '2.31.0' };
}

/** Coerce any stored value (Editor.js JSON, plain text, empty) into editor data. */
export function toEditorJsData(value) {
    const parsed = parseEditorJs(value);
    if (parsed) return parsed;
    if (value == null || String(value).trim() === '') return { time: 0, blocks: [], version: '2.31.0' };
    return textToEditorJs(String(value));
}

function stripInlineHtml(html) {
    return String(html)
        .replace(/<br\s*\/?>/gi, '\n')
        .replace(/<[^>]+>/g, '')
        .replace(/&nbsp;/g, ' ')
        .replace(/&amp;/g, '&')
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>');
}

function listItemsToText(items, style, depth = 0, counter = { n: 1 }) {
    const out = [];
    (items || []).forEach((item, idx) => {
        const content = typeof item === 'string' ? item : item?.content ?? '';
        const marker = style === 'ordered' ? `${idx + 1}.` : '•';
        out.push(`${'  '.repeat(depth)}${marker} ${stripInlineHtml(content)}`);
        if (item && typeof item === 'object' && Array.isArray(item.items) && item.items.length) {
            out.push(listItemsToText(item.items, style, depth + 1));
        }
    });
    return out.join('\n');
}

/** Flatten Editor.js data to readable plain text (excerpts, search, exports). */
export function editorJsToText(data) {
    if (!isEditorJsData(data)) return '';
    const parts = [];
    for (const block of data.blocks) {
        const d = block?.data || {};
        switch (block?.type) {
            case 'paragraph':
            case 'header':
                parts.push(stripInlineHtml(d.text ?? ''));
                break;
            case 'list':
                parts.push(listItemsToText(d.items, d.style));
                break;
            case 'quote':
                parts.push(stripInlineHtml(d.text ?? '') + (d.caption ? `\n— ${stripInlineHtml(d.caption)}` : ''));
                break;
            case 'code':
                parts.push(String(d.code ?? ''));
                break;
            case 'table':
                parts.push((d.content || []).map((row) => (row || []).map(stripInlineHtml).join(' | ')).join('\n'));
                break;
            case 'delimiter':
                parts.push('---');
                break;
            default:
                if (d.text) parts.push(stripInlineHtml(d.text));
        }
    }
    return parts.filter((p) => p.trim() !== '').join('\n\n').trim();
}
