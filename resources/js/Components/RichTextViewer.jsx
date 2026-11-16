import { RichTextRenderer } from '@thirdline/ui';

export default function RichTextViewer({ value, fallback = null, className = '' }) {
    const raw = value ?? fallback;

    if (!raw) {
        return <span className="text-gray-400">—</span>;
    }

    let parsed = null;
    if (typeof raw === 'object' && raw !== null && Array.isArray(raw.blocks)) {
        parsed = raw;
    } else if (typeof raw === 'string') {
        const trimmed = raw.trim();
        if (trimmed.startsWith('{') && trimmed.endsWith('}')) {
            try {
                const json = JSON.parse(trimmed);
                if (Array.isArray(json?.blocks)) {
                    parsed = json;
                }
            } catch {
                // Keep as plain text
            }
        }
    }

    if (parsed) {
        return (
            <div className={`prose prose-sm max-w-none dark:prose-invert ${className}`}>
                <RichTextRenderer data={parsed} />
            </div>
        );
    }

    return (
        <div className={`whitespace-pre-line text-sm text-gray-700 dark:text-gray-300 ${className}`}>
            {String(raw)}
        </div>
    );
}
