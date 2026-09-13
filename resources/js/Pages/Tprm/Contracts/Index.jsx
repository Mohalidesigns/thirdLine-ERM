import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * The contract register — TRD §8.6.
 *
 * THE FIRST TILE IS NOTICE, NOT EXPIRY, and that ordering is the whole
 * argument of FR-CTR-02. A contract expiring in ninety days with a
 * hundred-and-twenty-day notice period has already renewed; a register whose
 * headline number is "expiring soon" would show it as comfortably distant on
 * the day the decision was lost.
 *
 * "Not analysed" is a tile of its own rather than folded into "no gaps",
 * because a contract nobody has read has unknown gaps, not none — and those
 * two look identical on any dashboard that counts zeros.
 */
export default function Index({ summary = {}, grid, can = {} }) {
    const tiles = [
        {
            label: 'Notice due in 90 days',
            value: summary.notice_due_90 ?? 0,
            hint: 'decide before the window closes',
            tone: summary.notice_due_90 ? 'warn' : null,
        },
        {
            label: 'Blocking gaps',
            value: summary.blocking_gaps ?? 0,
            hint: 'contracts missing a condition of activation',
            tone: summary.blocking_gaps ? 'critical' : null,
        },
        {
            label: 'Not yet analysed',
            value: summary.unanalysed ?? 0,
            hint: 'in force, clauses unknown',
            tone: summary.unanalysed ? 'warn' : null,
        },
        { label: 'In force', value: summary.in_force ?? 0, hint: `of ${summary.total ?? 0} recorded` },
    ];

    return (
        <AppLayout title="Contracts">
            <Head title="Contracts" />

            <PageHeader
                title="Contracts"
                subtitle="What each agreement commits both sides to, and when the decision to renew or leave has to be made. Renewal is keyed to the notice period, not the expiry date."
            />

            <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                {tiles.map((tile) => (
                    <div key={tile.label} className="card p-4">
                        <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{tile.label}</p>
                        <p className={`mt-1 text-2xl font-semibold ${
                            tile.tone === 'critical' ? 'text-red-700' : tile.tone === 'warn' ? 'text-amber-700' : 'text-gray-900'
                        }`}>
                            {tile.value}
                        </p>
                        <p className="mt-0.5 text-xs text-gray-500">{tile.hint}</p>
                    </div>
                ))}
            </div>

            <DataGrid grid={grid} />
        </AppLayout>
    );
}
