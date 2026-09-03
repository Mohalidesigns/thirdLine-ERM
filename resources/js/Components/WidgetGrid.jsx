import Widget from '@/Components/Widget';

/**
 * The 12-column dashboard grid (migration Phase 2: hq/show.blade.php).
 * Placement is inline style because x/y/w/h are data, not a finite class list
 * Tailwind could see; the .hq-grid media rule collapses it to one column on
 * small screens.
 */
export default function WidgetGrid({ layout, payloads, keyPrefix = '' }) {
    return (
        <div className="hq-grid grid gap-4" style={{ gridTemplateColumns: 'repeat(12, minmax(0, 1fr))', gridAutoRows: '92px' }}>
            {layout.map((placement, index) => {
                const x = Math.max(0, Math.min(11, parseInt(placement.x ?? 0, 10) || 0));
                const w = Math.max(2, Math.min(12 - x, parseInt(placement.w ?? 4, 10) || 4));
                const y = Math.max(0, parseInt(placement.y ?? 0, 10) || 0);
                const h = Math.max(2, parseInt(placement.h ?? 3, 10) || 3);
                return (
                    <div key={`${keyPrefix}${index}-${placement.widget_id}`} className="hq-cell min-w-0" style={{ gridColumn: `${x + 1} / span ${w}`, gridRow: `${y + 1} / span ${h}` }}>
                        <Widget payload={payloads[index]} />
                    </div>
                );
            })}
        </div>
    );
}
