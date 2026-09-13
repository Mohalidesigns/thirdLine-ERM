import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import DataGrid from '@thirdline/ui/Components/DataGrid/DataGrid';
import PageHeader from '@thirdline/ui/Components/PageHeader';

/**
 * The obligation register — FR-CTR-06.
 *
 * "OWED BY US" IS THE FIRST TILE, and the placement is the argument. Almost
 * every obligation register holds the vendor's duties, and those are useful.
 * But the duties an institution is found to have breached are its own — the
 * quarterly access review the contract entitles it to and nobody scheduled,
 * the annual assurance report nobody obtained. A register that leads with the
 * vendor's side quietly agrees with the habit that causes the problem.
 *
 * "Nobody owns it" is a tile rather than a filter buried in a dropdown for the
 * same reason: an unowned duty is a duty nobody does, and it is invisible in
 * every other view.
 */
export default function Index({ summary = {}, grid, can = {} }) {
    const tiles = [
        {
            label: 'Owed by us',
            value: summary.ours ?? 0,
            hint: 'outstanding on our side',
            tone: null,
        },
        {
            label: 'Overdue',
            value: summary.overdue ?? 0,
            hint: 'past their date, either side',
            tone: summary.overdue ? 'critical' : null,
        },
        {
            label: 'Nobody owns it',
            value: summary.unowned ?? 0,
            hint: 'outstanding and unassigned',
            tone: summary.unowned ? 'warn' : null,
        },
        {
            label: 'Have been breached',
            value: summary.breached_ever ?? 0,
            hint: 'at least once, ever',
            tone: summary.breached_ever ? 'warn' : null,
        },
    ];

    return (
        <AppLayout title="Obligations">
            <Head title="Obligations" />

            <PageHeader
                title="Obligations"
                subtitle="What each contract and assurance report commits both sides to. Obligations run both ways — the ones owed by us are the ones a supervisor asks about."
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
