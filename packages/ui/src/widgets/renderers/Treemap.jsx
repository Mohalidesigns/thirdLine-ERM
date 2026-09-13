import { useEffect, useState } from 'react';
import { fmtNum, isDarkColor, readTokens } from '../theme';

/**
 * Squarified-ish treemap: rows of ~sqrt(n) tiles, flex-grown by value. Area
 * stays proportional, labels clamp. Port of the DOM renderer in widgets/index.js.
 */
export default function Treemap({ data }) {
    const [tokens, setTokens] = useState(() => readTokens());

    useEffect(() => {
        const media = window.matchMedia('(prefers-color-scheme: dark)');
        const onScheme = () => setTokens(readTokens());
        media.addEventListener('change', onScheme);
        return () => media.removeEventListener('change', onScheme);
    }, []);

    const tiles = (data.tiles || []).filter((x) => +x.value > 0).sort((a, b) => b.value - a.value);
    if (tiles.length === 0) return <p className="widget-empty-note">No data in scope</p>;

    const total = tiles.reduce((s, x) => s + +x.value, 0);
    const perRow = Math.max(1, Math.round(Math.sqrt(tiles.length)));
    const rows = [];
    for (let i = 0; i < tiles.length; i += perRow) rows.push({ start: i, tiles: tiles.slice(i, i + perRow) });

    return (
        <div className="widget-treemap">
            {rows.map(({ start, tiles: rowTiles }) => {
                const rowTotal = rowTiles.reduce((s, x) => s + +x.value, 0);
                return (
                    <div key={start} className="widget-treemap-row" style={{ flexGrow: rowTotal / total }}>
                        {rowTiles.map((tile, j) => {
                            const rank = start + j;
                            const color = tokens.sequential[Math.min(tokens.sequential.length - 1, rank < 2 ? 4 : rank < 5 ? 3 : 2)];
                            return (
                                <div
                                    key={`${tile.label}-${rank}`}
                                    className="widget-treemap-tile"
                                    style={{ flexGrow: +tile.value / rowTotal, backgroundColor: color, color: isDarkColor(color) ? '#f7fafc' : '#1a202c' }}
                                    title={`${tile.label}: ${fmtNum(tile.value)}`}
                                >
                                    <span className="widget-treemap-label">{tile.label}</span>
                                    <span className="widget-treemap-value">{fmtNum(tile.value)}</span>
                                </div>
                            );
                        })}
                    </div>
                );
            })}
        </div>
    );
}
