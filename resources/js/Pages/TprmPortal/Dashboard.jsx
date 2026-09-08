import { Link } from '@inertiajs/react';
import PortalLayout from '@/Layouts/PortalLayout';

/**
 * FR-PRT-02. Opens with what is owed, not with a welcome.
 *
 * A vendor signs in because somebody asked them for something. The trust
 * score and the profile prompt are below that, because they are what WE want
 * the vendor to do rather than what the vendor came to do — put them first and
 * the page is an advert.
 */
export default function Dashboard({
    vendor, openRequests = [], expiringDocuments = [], openFindings = [],
    unreadMessages = 0, trustProfile = {}, trustScore = {},
}) {
    const nothingOwed = openRequests.length === 0 && openFindings.length === 0 && expiringDocuments.length === 0;

    return (
        <PortalLayout title="Overview">
            <h1 className="mb-1 text-lg font-semibold text-gray-900">{vendor}</h1>
            <p className="mb-6 text-sm text-gray-600">
                {nothingOwed
                    ? 'Nothing is outstanding. Anything new will appear here first.'
                    : 'What your client is waiting on.'}
            </p>

            {unreadMessages > 0 && (
                <div className="mb-6 rounded border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                    You have {unreadMessages} unread {unreadMessages === 1 ? 'message' : 'messages'} from your
                    client's reviewer.
                </div>
            )}

            <div className="grid gap-6 lg:grid-cols-3">
                <section className="lg:col-span-2 space-y-6">
                    <Panel title="Assessments awaiting you" empty="Nothing to answer right now.">
                        {openRequests.map((request) => (
                            <Link
                                key={request.uuid}
                                href={route('tprm-portal.assessments.show', request.uuid)}
                                className="block px-4 py-3 hover:bg-gray-50"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="text-sm font-medium text-gray-900">{request.name}</span>
                                    <span className={`text-xs ${request.overdue ? 'font-semibold text-red-700' : 'text-gray-500'}`}>
                                        {request.due_at ? `Due ${request.due_at}` : 'No due date'}
                                    </span>
                                </div>
                                <ProgressBar progress={request.progress} />
                            </Link>
                        ))}
                    </Panel>

                    <Panel title="Findings against you" empty="No open findings.">
                        {openFindings.map((finding) => (
                            <Link
                                key={finding.uuid}
                                href={route('tprm-portal.findings.show', finding.uuid)}
                                className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 hover:bg-gray-50"
                            >
                                <span className="min-w-0 text-sm text-gray-900">
                                    <span className="font-mono text-xs text-gray-500">{finding.reference}</span>{' '}
                                    {finding.title}
                                </span>
                                <Severity value={finding.severity} label={finding.severity_label} />
                            </Link>
                        ))}
                    </Panel>

                    <Panel title="Documents expiring" empty="Nothing expiring in the next 90 days.">
                        {expiringDocuments.map((document, index) => (
                            <div key={index} className="flex items-center justify-between px-4 py-3">
                                <span className="text-sm text-gray-900">{document.title}</span>
                                <span className={`text-xs ${document.expired ? 'font-semibold text-red-700' : 'text-amber-700'}`}>
                                    {document.expired ? `Expired ${document.valid_to}` : `Expires ${document.valid_to}`}
                                </span>
                            </div>
                        ))}
                    </Panel>
                </section>

                <aside className="space-y-6">
                    <TrustScoreCard score={trustScore} />
                    <TrustProfileCard profile={trustProfile} />
                </aside>
            </div>
        </PortalLayout>
    );
}

function Panel({ title, empty, children }) {
    const rows = Array.isArray(children) ? children.filter(Boolean) : children;
    const isEmpty = !rows || (Array.isArray(rows) && rows.length === 0);

    return (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <h2 className="border-b border-gray-100 px-4 py-2 text-sm font-semibold text-gray-900">{title}</h2>
            {isEmpty
                ? <p className="px-4 py-6 text-center text-sm text-gray-500">{empty}</p>
                : <div className="divide-y divide-gray-100">{rows}</div>}
        </div>
    );
}

function ProgressBar({ progress = {} }) {
    const pct = progress.pct ?? 0;

    return (
        <div className="mt-2">
            <div className="h-1.5 w-full overflow-hidden rounded bg-gray-100">
                <div className="h-full bg-blue-600" style={{ width: `${pct}%` }} />
            </div>
            <p className="mt-1 text-xs text-gray-500">
                {progress.answered ?? 0} of {progress.total ?? 0} answered
                {progress.required_total > 0 && (
                    <> · {progress.required_answered}/{progress.required_total} required</>
                )}
            </p>
        </div>
    );
}

function Severity({ value, label }) {
    const tone = {
        critical: 'bg-red-50 text-red-800',
        high: 'bg-orange-50 text-orange-800',
        medium: 'bg-amber-50 text-amber-800',
    }[value] ?? 'bg-gray-100 text-gray-700';

    return <span className={`shrink-0 rounded px-1.5 py-0.5 text-xs font-medium ${tone}`}>{label}</span>;
}

/**
 * TRD §7.7 — `100 − RR`, the same number the client sees, inverted.
 *
 * Not a separate vendor-friendly metric. The moment the two can disagree the
 * vendor is being managed rather than informed, and the first time somebody
 * notices — in a contract negotiation, usually — it has cost more trust than
 * it built.
 */
function TrustScoreCard({ score = {} }) {
    if (score.unavailable) {
        return (
            <div className="rounded-lg border border-gray-200 bg-white p-4">
                <h2 className="text-sm font-semibold text-gray-900">Your trust score</h2>
                <p className="mt-2 text-sm text-gray-500">{score.unavailable}</p>
            </div>
        );
    }

    const tone = {
        strong: 'text-green-700',
        adequate: 'text-blue-700',
        'needs improvement': 'text-amber-700',
        weak: 'text-red-700',
    }[score.band] ?? 'text-gray-900';

    return (
        <div className="rounded-lg border border-gray-200 bg-white p-4">
            <h2 className="text-sm font-semibold text-gray-900">Your trust score</h2>
            <p className={`mt-1 text-3xl font-semibold ${tone}`}>{score.score}</p>
            <p className="text-xs capitalize text-gray-500">{score.band} · as at {score.as_of}</p>

            <dl className="mt-4 space-y-3">
                {(score.domains ?? []).map((domain) => (
                    <div key={domain.key}>
                        <dt className="flex items-baseline justify-between text-xs font-medium text-gray-700">
                            <span>{domain.label}</span>
                            <span className="tabular-nums">{domain.value}{domain.unit === '%' ? '%' : ''}</span>
                        </dt>
                        <dd className="mt-0.5 text-xs text-gray-500">{domain.note}</dd>
                    </div>
                ))}
            </dl>

            {(score.improvements ?? []).length > 0 && (
                <div className="mt-4 border-t border-gray-100 pt-3">
                    <p className="text-xs font-medium text-gray-700">Closing these would move it most</p>
                    <ul className="mt-2 space-y-1">
                        {score.improvements.map((item) => (
                            <li key={item.reference} className="text-xs">
                                <Link
                                    href={route('tprm-portal.findings.show', item.uuid)}
                                    className="text-blue-700 hover:underline"
                                >
                                    {item.reference}
                                </Link>{' '}
                                <span className="text-gray-600">{item.title}</span>
                                {item.overdue && <span className="ml-1 text-red-700">overdue</span>}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

/** The behavioural lever: what the vendor GETS, not what we want. */
function TrustProfileCard({ profile = {} }) {
    return (
        <div className="rounded-lg border border-gray-200 bg-white p-4">
            <h2 className="text-sm font-semibold text-gray-900">Your trust profile</h2>

            <div className="mt-2 h-1.5 w-full overflow-hidden rounded bg-gray-100">
                <div className="h-full bg-green-600" style={{ width: `${profile.completeness ?? 0}%` }} />
            </div>
            <p className="mt-1 text-xs text-gray-500">
                {profile.completeness ?? 0}% complete
                {profile.published_version > 0
                    ? ` · published v${profile.published_version}`
                    : ' · not yet published'}
            </p>

            {profile.tenant_count > 1 && (
                <p className="mt-2 text-xs text-gray-600">
                    You serve {profile.tenant_count} organisations here. One profile answers all of them.
                </p>
            )}

            {(profile.next_best ?? []).length > 0 && (
                <div className="mt-3 space-y-2">
                    {profile.next_best.map((section) => (
                        <div key={section.key} className="rounded bg-gray-50 p-2">
                            <p className="text-xs font-medium text-gray-800">{section.label}</p>
                            <p className="mt-0.5 text-xs text-gray-600">{section.unlocks}</p>
                        </div>
                    ))}
                </div>
            )}

            <Link
                href={route('tprm-portal.trust-profile.show')}
                className="mt-3 inline-block text-xs font-medium text-blue-700 hover:underline"
            >
                Open the profile →
            </Link>
        </div>
    );
}
