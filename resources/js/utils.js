import { editorJsToText, isEditorJsData } from '@/lib/richtext';

export function formatDate(dateStr) {
    if (!dateStr) return '-';
    try {
        const d = new Date(dateStr);
        return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    } catch {
        return dateStr;
    }
}

export function formatDateTime(dateStr) {
    if (!dateStr) return '-';
    try {
        const d = new Date(dateStr);
        return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    } catch {
        return dateStr;
    }
}

export function formatCurrency(amount, currency = 'NGN') {
    if (amount == null) return '-';
    return new Intl.NumberFormat('en-NG', { style: 'currency', currency, minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(amount);
}

export function formatNumber(num) {
    if (num == null) return '-';
    return new Intl.NumberFormat('en-NG').format(num);
}

export function classNames(...classes) {
    return classes.filter(Boolean).join(' ');
}

export function daysUntil(dateStr) {
    if (!dateStr) return null;
    const target = new Date(dateStr);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    target.setHours(0, 0, 0, 0);
    return Math.ceil((target - today) / (1000 * 60 * 60 * 24));
}

export function truncate(str, length = 50) {
    if (!str) return '';
    return str.length > length ? str.substring(0, length) + '...' : str;
}

// Escape raw control characters inside JSON string literals (LLMs emit
// pretty-printed JSON with literal newlines/tabs inside strings, which is
// invalid). Structural whitespace between tokens is left untouched.
function escapeJsonControlChars(json) {
    let out = '';
    let inString = false;
    let escaped = false;
    for (let i = 0; i < json.length; i++) {
        const ch = json[i];
        if (escaped) { out += ch; escaped = false; continue; }
        if (ch === '\\') { out += ch; escaped = true; continue; }
        if (ch === '"') { inString = !inString; out += ch; continue; }
        if (inString && ch.charCodeAt(0) < 0x20) {
            if (ch === '\n') out += '\\n';
            else if (ch === '\r') out += '\\r';
            else if (ch === '\t') out += '\\t';
            else out += '\\u' + ch.charCodeAt(0).toString(16).padStart(4, '0');
            continue;
        }
        out += ch;
    }
    return out;
}

function tryParseJson(str) {
    if (typeof str !== 'string') return null;
    const trimmed = str.trim();
    if (!trimmed || (trimmed[0] !== '{' && trimmed[0] !== '[')) return null;
    try {
        const parsed = JSON.parse(trimmed);
        return parsed && typeof parsed === 'object' ? parsed : null;
    } catch {
        try {
            const parsed = JSON.parse(escapeJsonControlChars(trimmed));
            return parsed && typeof parsed === 'object' ? parsed : null;
        } catch {
            return null;
        }
    }
}

function flattenReportValue(value, depth = 0) {
    if (value === null || value === undefined) return '';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (typeof value === 'number') return String(value);

    if (typeof value === 'string') {
        const parsed = tryParseJson(value);
        if (parsed !== null) {
            // Editor.js documents flatten via the rich-text helper, not the
            // generic object flattener.
            if (isEditorJsData(parsed)) return editorJsToText(parsed);
            return flattenReportValue(parsed, depth);
        }
        return value.trim();
    }

    if (isEditorJsData(value)) return editorJsToText(value);

    if (Array.isArray(value)) {
        const parts = value
            .map((item) => {
                const text = flattenReportValue(item, depth + 1);
                if (!text || !text.trim()) return '';
                return item !== null && typeof item === 'object' ? text : (depth > 0 ? `• ${text}` : text);
            })
            .filter((p) => p && p.trim());
        return parts.join(depth > 0 ? '\n' : '\n\n');
    }

    if (typeof value === 'object') {
        const lines = [];
        for (const [key, item] of Object.entries(value)) {
            const label = key.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());
            const text = flattenReportValue(item, depth + 1);
            if (!text || !text.trim()) continue;
            if (text.includes('\n') || text.length > 80) lines.push(`${label}:\n${text}`);
            else lines.push(`${label}: ${text}`);
        }
        return lines.join('\n\n');
    }

    return '';
}

/**
 * Render a report narrative section as clean readable text. Most sections are
 * already plain prose, but some legacy records hold a JSON-encoded array/object
 * (or the whole nested report) — those are flattened instead of dumped raw.
 */
export function formatReportSection(value) {
    return flattenReportValue(value, 0).trim();
}
