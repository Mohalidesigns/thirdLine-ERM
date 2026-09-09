import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * Provider health and spend.
 *
 * DELIVERY RATE IS AGAINST ATTEMPTS, NOT AGAINST ACCEPTED MESSAGES. A Nigerian
 * gateway that accepts everything and delivers nothing is the exact failure
 * this screen exists to catch, and a rate computed against what it accepted
 * would show it at 100%.
 *
 * A PROVIDER WITH NO TRAFFIC HAS NO DELIVERY RATE — it shows a dash, not a
 * green 100%. A gateway configured last week and never used is neither perfect
 * nor broken, and an operator who relies on a green tick during an emergency
 * has been misled by this screen.
 */
export default function Providers({ providers = [], spend = {}, channels = [] }) {
    const money = (minor, currency) =>
        minor === null || minor === undefined ? '—' : `${currency ?? 'NGN'} ${(minor / 100).toFixed(2)}`;

    return (
        <AppLayout>
            <Head title="Providers and spend" />

            <PageHeader
                title="Providers and spend"
                subtitle="Which gateways are working, and what they cost."
                actions={(
                    <Link href={tryRoute('bcms.emns.index')}
                        className="rounded border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                        Console
                    </Link>
                )}
            />

            <section className="mb-6 rounded border border-slate-200 bg-white p-4">
                <h2 className="mb-3 text-sm font-semibold text-slate-700">Channels</h2>
                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    {channels.map((c) => (
                        <div key={c.channel} className="rounded border border-slate-200 p-3">
                            <div className="flex items-center justify-between">
                                <span className="text-sm font-medium text-slate-800">{c.label}</span>
                                <span className={`rounded px-1.5 py-0.5 text-[10px] font-medium ${
                                    c.status === 'live' ? 'bg-emerald-100 text-emerald-800'
                                        : c.status === 'awaiting_credentials' ? 'bg-amber-100 text-amber-800'
                                            : 'bg-slate-100 text-slate-600'
                                }`}>
                                    {c.status === 'live' ? 'live'
                                        : c.status === 'awaiting_credentials' ? 'awaiting credentials'
                                            : 'not enabled'}
                                </span>
                            </div>
                            <div className="mt-1 text-[11px] text-slate-500">{c.provider}</div>
                            <div className="mt-1 flex gap-2 text-[10px] text-slate-400">
                                {c.offline_capable && <span>works without data</span>}
                                {c.supports_inbound_ack && <span>two-way</span>}
                            </div>
                        </div>
                    ))}
                </div>
            </section>

            <section className="mb-6 rounded border border-slate-200 bg-white">
                <h2 className="border-b border-slate-100 px-4 py-2 text-sm font-semibold text-slate-700">
                    Gateway health — last 30 days
                </h2>
                {providers.length === 0 ? (
                    <p className="px-4 py-6 text-sm text-slate-500">
                        No messages have been dispatched yet, so there is nothing to measure.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-4 py-2">Provider</th>
                                    <th className="px-4 py-2 text-right">Attempts</th>
                                    <th className="px-4 py-2 text-right">Delivery rate</th>
                                    <th className="px-4 py-2 text-right">Median latency</th>
                                    <th className="px-4 py-2 text-right">Cost</th>
                                    <th className="px-4 py-2">Top failures</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {providers.map((p) => (
                                    <tr key={p.provider} className={p.is_cooling_off ? 'bg-rose-50' : ''}>
                                        <td className="px-4 py-2">
                                            <span className="font-medium text-slate-800">{p.provider}</span>
                                            {p.is_cooling_off && (
                                                <span className="ml-2 rounded bg-rose-600 px-1.5 py-0.5 text-[10px] text-white">
                                                    demoted
                                                </span>
                                            )}
                                            <div className="text-[11px] text-slate-500">{p.channel}</div>
                                        </td>
                                        <td className="px-4 py-2 text-right tabular-nums">{p.attempts}</td>
                                        <td className={`px-4 py-2 text-right tabular-nums ${
                                            p.delivery_rate === null ? 'text-slate-400'
                                                : p.delivery_rate < 80 ? 'text-rose-600' : 'text-slate-800'
                                        }`}>
                                            {p.delivery_rate === null ? '—' : `${p.delivery_rate}%`}
                                        </td>
                                        <td className="px-4 py-2 text-right tabular-nums text-slate-600">
                                            {p.median_latency_seconds === null ? '—' : `${p.median_latency_seconds}s`}
                                        </td>
                                        <td className="px-4 py-2 text-right tabular-nums text-slate-600">
                                            {money(p.cost_minor, p.currency)}
                                        </td>
                                        <td className="px-4 py-2 text-[11px] text-slate-500">
                                            {p.top_failures.length === 0
                                                ? '—'
                                                : p.top_failures.map((f) => `${f.reason} (${f.count})`).join('; ')}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            <section className="rounded border border-slate-200 bg-white p-4">
                <h2 className="mb-3 text-sm font-semibold text-slate-700">Spend</h2>
                <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div>
                        <div className="text-2xl font-semibold text-slate-800">
                            {money(spend.total_minor, spend.currency)}
                        </div>
                        <div className="text-xs text-slate-500">Last 6 months</div>
                    </div>
                    <div>
                        <div className="text-2xl font-semibold text-slate-800">{spend.priced_messages ?? 0}</div>
                        <div className="text-xs text-slate-500">Priced messages</div>
                    </div>
                    <div>
                        <div className="text-2xl font-semibold text-slate-500">{spend.unpriced_messages ?? 0}</div>
                        <div className="text-xs text-slate-500">Not priced by the provider</div>
                    </div>
                </div>

                {(spend.unpriced_messages ?? 0) > 0 && (
                    <p className="mb-3 rounded bg-slate-50 p-2 text-[11px] text-slate-600">
                        The total covers only the messages a provider reported a cost for. Email, Teams and
                        Slack are not metered; a total that counted them as free would understate nothing,
                        but a gateway that simply did not report would.
                    </p>
                )}

                {(spend.by_channel ?? []).length > 0 && (
                    <table className="w-full text-sm">
                        <thead className="text-left text-xs uppercase text-slate-500">
                            <tr><th className="pb-2">Channel</th><th className="pb-2 text-right">Messages</th><th className="pb-2 text-right">Cost</th></tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {spend.by_channel.map((c) => (
                                <tr key={c.channel}>
                                    <td className="py-2 capitalize">{c.channel}</td>
                                    <td className="py-2 text-right tabular-nums">{c.messages}</td>
                                    <td className="py-2 text-right tabular-nums">{money(c.cost_minor, spend.currency)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>
        </AppLayout>
    );
}
