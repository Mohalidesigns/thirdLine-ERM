/**
 * The live 5×5 heat position of §8.3 — where this risk sits inherent versus
 * residual.
 *
 * Drawn from the METHODOLOGY's own scales and bands rather than a hardcoded
 * grid, so a tenant on a 4×4 or a 6×6 gets their own matrix. The cell colour
 * comes from the band that contains likelihood × impact, which is the same
 * banding the server uses — so the picture and the number can never disagree.
 */
export default function HeatPosition({ line, methodology }) {
    const likelihood = methodology.likelihood ?? [];
    const impact = methodology.impact ?? [];

    const bandFor = (score) => {
        const ordered = [...(methodology.bands ?? [])].sort((a, b) => a.minScore - b.minScore);

        return ordered.find((band) => score >= band.minScore && score <= band.maxScore) ?? ordered[ordered.length - 1];
    };

    // Residual is fractional and does not land on a cell, so it is placed at
    // the inherent position of the nearest whole score rather than pretending
    // it has coordinates of its own.
    const inherentCell =
        line.inherent_likelihood && line.inherent_impact
            ? `${line.inherent_likelihood}:${line.inherent_impact}`
            : null;

    return (
        <div className="rounded-lg border border-gray-200 bg-white p-4">
            <p className="text-xs uppercase tracking-wider text-gray-500">Where this sits</p>

            <table className="mt-3 w-full border-separate border-spacing-0.5 text-center">
                <tbody>
                    {[...likelihood].reverse().map((l) => (
                        <tr key={l.value}>
                            <th className="pr-1 text-right text-[10px] font-normal text-gray-400">{l.value}</th>
                            {impact.map((i) => {
                                const score = l.value * i.value;
                                const band = bandFor(score);
                                const isHere = inherentCell === `${l.value}:${i.value}`;

                                return (
                                    <td
                                        key={i.value}
                                        title={`${l.label} × ${i.label} = ${score} (${band?.label ?? ''})`}
                                        className={`h-7 rounded text-[10px] ${isHere ? 'ring-2 ring-gray-900' : ''}`}
                                        style={{ backgroundColor: (band?.colour ?? '#e5e7eb') + (isHere ? '' : '66') }}
                                    >
                                        {isHere ? score : ''}
                                    </td>
                                );
                            })}
                        </tr>
                    ))}
                    <tr>
                        <th />
                        {impact.map((i) => (
                            <th key={i.value} className="pt-1 text-[10px] font-normal text-gray-400">
                                {i.value}
                            </th>
                        ))}
                    </tr>
                </tbody>
            </table>

            <p className="mt-2 text-[11px] text-gray-500">Likelihood (rows) × Impact (columns)</p>

            {line.residual_score !== null && line.residual_score !== undefined && (
                <p className="mt-2 text-sm text-gray-700">
                    Residual <strong>{Number(line.residual_score).toFixed(2)}</strong>{' '}
                    <span className="text-gray-500">
                        ({bandFor(Number(line.residual_score))?.label ?? '—'})
                    </span>
                </p>
            )}
        </div>
    );
}
