import { Fragment } from 'react';
import { parseEditorJs } from '../lib/richtext';
import { formatReportSection } from '../utils';

// Inline marks Editor.js may embed inside block text. Everything else is
// unwrapped to its text content — no HTML ever reaches the DOM unparsed.
const ALLOWED_INLINE = { b: 'b', strong: 'strong', i: 'i', em: 'em', u: 'u', code: 'code', mark: 'mark', s: 's', br: 'br', a: 'a' };

function safeHref(href) {
    if (!href) return null;
    const trimmed = String(href).trim();
    return /^(https?:|mailto:)/i.test(trimmed) ? trimmed : null;
}

function nodesToReact(nodes, keyPrefix = 'n') {
    const out = [];
    nodes.forEach((node, idx) => {
        const key = `${keyPrefix}-${idx}`;
        if (node.nodeType === Node.TEXT_NODE) {
            out.push(node.textContent);
            return;
        }
        if (node.nodeType !== Node.ELEMENT_NODE) return;

        const tag = ALLOWED_INLINE[node.tagName.toLowerCase()];
        const children = nodesToReact(Array.from(node.childNodes), key);
        if (!tag) {
            // Unknown element — keep its text, drop the markup.
            out.push(<Fragment key={key}>{children}</Fragment>);
            return;
        }
        if (tag === 'br') {
            out.push(<br key={key} />);
            return;
        }
        if (tag === 'a') {
            const href = safeHref(node.getAttribute('href'));
            out.push(
                href
                    ? <a key={key} href={href} target="_blank" rel="noopener noreferrer" className="text-indigo-600 underline">{children}</a>
                    : <Fragment key={key}>{children}</Fragment>
            );
            return;
        }
        const Tag = tag;
        out.push(<Tag key={key}>{children}</Tag>);
    });
    return out;
}

/** Render an Editor.js inline-HTML string as React elements (whitelist only). */
function Inline({ html }) {
    if (html == null || html === '') return null;
    if (typeof window === 'undefined') return String(html);
    const doc = new DOMParser().parseFromString(`<div>${html}</div>`, 'text/html');
    return <>{nodesToReact(Array.from(doc.body.firstChild.childNodes))}</>;
}

function ListItems({ items, style }) {
    const Tag = style === 'ordered' ? 'ol' : 'ul';
    return (
        <Tag className={`${style === 'ordered' ? 'list-decimal' : 'list-disc'} pl-5 space-y-0.5`}>
            {(items || []).map((item, idx) => {
                const content = typeof item === 'string' ? item : item?.content ?? '';
                const nested = item && typeof item === 'object' && Array.isArray(item.items) && item.items.length > 0;
                return (
                    <li key={idx}>
                        <Inline html={content} />
                        {nested && <ListItems items={item.items} style={style} />}
                    </li>
                );
            })}
        </Tag>
    );
}

function Block({ block }) {
    const d = block?.data || {};
    switch (block?.type) {
        case 'paragraph':
            return <p><Inline html={d.text} /></p>;
        case 'header': {
            const level = Math.min(Math.max(parseInt(d.level, 10) || 3, 1), 6);
            const Tag = `h${level}`;
            return <Tag className="font-semibold text-gray-800"><Inline html={d.text} /></Tag>;
        }
        case 'list':
            return <ListItems items={d.items} style={d.style} />;
        case 'quote':
            return (
                <blockquote className="border-l-4 border-gray-300 pl-3 italic text-gray-600">
                    <Inline html={d.text} />
                    {d.caption ? <cite className="block text-xs not-italic text-gray-400 mt-1"><Inline html={d.caption} /></cite> : null}
                </blockquote>
            );
        case 'code':
            return (
                <pre className="bg-gray-50 border border-gray-200 rounded p-2 text-xs overflow-x-auto">
                    <code>{String(d.code ?? '')}</code>
                </pre>
            );
        case 'table': {
            const rows = Array.isArray(d.content) ? d.content : [];
            if (!rows.length) return null;
            const [head, ...body] = rows;
            const withHeadings = !!d.withHeadings;
            return (
                <div className="overflow-x-auto">
                    <table className="min-w-full border border-gray-200 text-sm">
                        {withHeadings && (
                            <thead>
                                <tr>
                                    {(head || []).map((cell, i) => (
                                        <th key={i} className="border border-gray-200 bg-gray-50 px-2 py-1 text-left font-semibold"><Inline html={cell} /></th>
                                    ))}
                                </tr>
                            </thead>
                        )}
                        <tbody>
                            {(withHeadings ? body : rows).map((row, r) => (
                                <tr key={r}>
                                    {(row || []).map((cell, c) => (
                                        <td key={c} className="border border-gray-200 px-2 py-1 align-top"><Inline html={cell} /></td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            );
        }
        case 'delimiter':
            return <hr className="border-gray-200" />;
        default:
            return d.text ? <p><Inline html={d.text} /></p> : null;
    }
}

/**
 * Read-only display counterpart of RichTextEditor. Renders Editor.js JSON as
 * React elements; legacy plain-text (or legacy JSON-ish) values fall back to
 * the existing flattened-text rendering.
 */
export default function RichTextRenderer({ value, className = '' }) {
    const data = parseEditorJs(value);

    if (!data) {
        const text = formatReportSection(value);
        if (!text) return null;
        return <div className={`whitespace-pre-wrap ${className}`}>{text}</div>;
    }

    return (
        <div className={`space-y-2 ${className}`}>
            {data.blocks.map((block, idx) => <Block key={block.id ?? idx} block={block} />)}
        </div>
    );
}
