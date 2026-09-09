import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * The template library, led by the language coverage grid.
 *
 * THE GRID IS THE POINT. The question this screen exists to answer is "which
 * scenarios can we actually send, in which languages" — and a bank that
 * believes it has five-language cover and does not will find that out during an
 * evacuation. Three states, not two: authored and live, authored and awaiting a
 * reviewer who reads the language, and never authored at all. They need
 * different people to act.
 *
 * NOTHING HERE IS MACHINE-TRANSLATED and the screen says so. An evacuation
 * instruction that says the wrong thing in Hausa is a safety incident, not a
 * formatting bug.
 */
const STATE_STYLES = {
    live: 'bg-emerald-500',
    awaiting_review: 'bg-amber-400',
    not_authored: 'bg-slate-200',
};

const STATE_LABELS = {
    live: 'Live',
    awaiting_review: 'Awaiting review',
    not_authored: 'Not authored',
};

export default function Templates({ coverage = {}, templates = [], can = {} }) {
    const [selected, setSelected] = useState(null);
    const locales = coverage.locales ?? [];
    const scenarios = coverage.scenarios ?? [];

    const rows = selected
        ? templates.filter((t) => t.code === selected)
        : [];

    return (
        <AppLayout>
            <Head title="Alert templates" />

            <PageHeader
                title="Alert templates"
                subtitle="Pre-approved emergency messaging, per scenario and per language."
                actions={(
                    <Link href={tryRoute('bcms.emns.index')}
                        className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                        Console
                    </Link>
                )}
            />

            <p className="mb-4 rounded border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
                {coverage.note}
            </p>

            <section className="mb-6 rounded border border-slate-200 bg-white">
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-4 py-2">Scenario</th>
                                {locales.map((l) => (
                                    <th key={l.code} className="px-3 py-2 text-center">{l.label}</th>
                                ))}
                                <th className="px-4 py-2 text-right">Cover</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {scenarios.map((s) => (
                                <tr key={s.code}
                                    className={`cursor-pointer hover:bg-slate-50 ${selected === s.code ? 'bg-slate-50' : ''}`}
                                    onClick={() => setSelected(selected === s.code ? null : s.code)}>
                                    <td className="px-4 py-2">
                                        <span className="font-medium text-slate-800">{s.name}</span>
                                        {s.is_life_safety && (
                                            <span className="ml-2 rounded bg-rose-600 px-1.5 py-0.5 text-[10px] font-semibold text-white">
                                                life safety
                                            </span>
                                        )}
                                        <div className="text-[11px] text-slate-500">{s.code} · {s.category}</div>
                                    </td>
                                    {locales.map((l) => {
                                        const cell = s.locales?.[l.code] ?? { state: 'not_authored' };
                                        return (
                                            <td key={l.code} className="px-3 py-2 text-center">
                                                <span title={STATE_LABELS[cell.state]}
                                                    className={`inline-block h-3 w-3 rounded-full ${STATE_STYLES[cell.state]}`} />
                                            </td>
                                        );
                                    })}
                                    <td className="px-4 py-2 text-right text-xs tabular-nums text-slate-600">
                                        {s.live_locales} / {locales.length}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="flex flex-wrap gap-4 border-t border-slate-100 px-4 py-2 text-[11px] text-slate-600">
                    {Object.entries(STATE_LABELS).map(([key, label]) => (
                        <span key={key} className="flex items-center gap-1">
                            <span className={`h-2.5 w-2.5 rounded-full ${STATE_STYLES[key]}`} /> {label}
                        </span>
                    ))}
                </div>
            </section>

            {selected && (
                <section className="rounded border border-slate-200 bg-white p-4">
                    <h2 className="mb-3 text-sm font-semibold text-slate-700">{selected} — wording</h2>
                    <ul className="space-y-3">
                        {rows.map((t) => (
                            <li key={t.id} className="rounded border border-slate-200 p-3">
                                <div className="mb-1 flex flex-wrap items-center justify-between gap-2">
                                    <span className="text-sm font-medium text-slate-800">
                                        {coverage.locales?.find((l) => l.code === t.locale)?.label ?? t.locale}
                                    </span>
                                    <span className="flex items-center gap-2 text-[11px]">
                                        <span className={t.is_active ? 'text-emerald-700' : 'text-amber-700'}>
                                            {t.is_active ? 'live' : 'awaiting review'}
                                        </span>
                                        {can.manage && !t.is_system_default && (
                                            <button type="button"
                                                onClick={() => router.post(
                                                    tryRoute('bcms.alert-templates.activate', t.id), {}, { preserveScroll: true },
                                                )}
                                                className="rounded border border-slate-300 px-2 py-0.5 hover:bg-slate-50">
                                                {t.is_active ? 'Withdraw' : 'Activate'}
                                            </button>
                                        )}
                                    </span>
                                </div>
                                <p className="whitespace-pre-wrap rounded bg-slate-50 p-2 text-sm text-slate-700">
                                    {t.body}
                                </p>
                                <div className="mt-2 flex flex-wrap gap-3 text-[11px] text-slate-500">
                                    <span>{t.sms?.characters} chars</span>
                                    <span className={t.sms?.segments > 2 ? 'text-amber-700' : ''}>
                                        {t.sms?.segments} SMS segment{t.sms?.segments === 1 ? '' : 's'}
                                    </span>
                                    {!t.sms?.is_gsm7 && (
                                        <span className="text-amber-700">
                                            non-GSM characters ({t.sms.non_gsm_characters.join(' ')}) — this
                                            more than doubles the cost per message
                                        </span>
                                    )}
                                    {t.sms?.will_truncate && (
                                        <span className="text-amber-700">will be shortened on SMS</span>
                                    )}
                                    {t.whatsapp_template_name && <span>WABA: {t.whatsapp_template_name}</span>}
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </AppLayout>
    );
}
