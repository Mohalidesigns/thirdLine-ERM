import { useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import FormField from '@thirdline/ui/Components/FormField';
import Pagination from '@thirdline/ui/Components/Pagination';
import tryRoute from '@thirdline/ui/lib/tryRoute';

/**
 * Training & competency — `docs/bcms/screens/training-compliance.md`.
 *
 * ATTENDANCE AND COMPETENCY ARE TWO COLUMNS, NEVER BLENDED. A single "trained"
 * indicator would let a bank report awareness as competence, which is the one
 * thing clause 7.2 exists to prevent.
 *
 * NO CERTIFICATE FIELD ANYWHERE (ADR 0021 §3). The assessor, the date and the
 * score are the record.
 *
 * THERE IS NO ASSESSOR PICKER ANYWHERE ON THIS SCREEN (code-review B4) —
 * `TrainingComplianceService::recordOutcome()`/`assess()` always use the
 * acting user, ignore any submitted `assessor_id`, and refuse both
 * self-assessment and re-assessing an already-scored record. A picker the
 * server ignores is a control that silently does nothing, so this screen
 * shows a read-only "Assessed by: {current user}" line instead (from the
 * shared `auth.user` prop) and surfaces the server's refusal — self-
 * assessment, already assessed, curriculum does not require assessment — as
 * the `flash.error` banner every `InvalidArgumentException` on this
 * controller comes back as.
 *
 * THE REGISTER PAGINATES SERVER-SIDE (`TrainingController::compliance()`'s
 * hand-rolled `{current_page,last_page,per_page,total}`, not a Laravel
 * paginator) — `buildPaginationLinks`/`buildPaginationMeta` below adapt that
 * into the `{links,meta}` shape the shared `Pagination` component expects,
 * carrying the current filters into every page link so a page change never
 * drops them.
 */
export default function Compliance({
    curricula = [], rows = [], summary = {}, users = [], departments = [], filters = {}, pagination = null, can = {},
}) {
    const { flash, auth } = usePage().props;
    const currentUserName = auth?.user?.name ?? null;

    const [recording, setRecording] = useState(false);
    const [creatingCurriculum, setCreatingCurriculum] = useState(false);
    const [assessingRow, setAssessingRow] = useState(null);

    const complianceUrl = tryRoute('bcms.training.compliance');

    const filter = (key, value) => router.get(complianceUrl, {
        ...filters, [key]: value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });

    const record = useForm({ curriculum_id: '', user_id: '', completed_at: '', score: '' });
    const create = useForm({
        code: '', name: '', description: '', target_roles: [], frequency_months: 12,
        is_mandatory: false, requires_assessment: false, pass_mark: '',
    });

    const submitRecord = (e) => {
        e.preventDefault();
        record.post(tryRoute('bcms.training-records.store'), {
            preserveScroll: true,
            onSuccess: () => record.reset(),
        });
    };

    const submitCurriculum = (e) => {
        e.preventDefault();
        create.transform((data) => ({ ...data, target_roles: data.target_roles.split(',').map((r) => r.trim()).filter(Boolean) }));
        create.post(tryRoute('bcms.training-curricula.store'), {
            preserveScroll: true,
            onSuccess: () => { create.reset(); setCreatingCurriculum(false); },
        });
    };

    const selectedCurriculum = curricula.find((c) => String(c.id) === String(record.data.curriculum_id));

    // `rows` is one page of the paginated register, so the "attended, not
    // assessed at volume" flag is computed on the server over the whole
    // viewer-scoped register (TrainingComplianceService::
    // attendedNotAssessedByCurriculum()) and read here, never re-derived
    // from the page.
    const heavyAmberCurricula = curricula.filter((c) => c.heavy_amber);

    const paginationLinks = buildPaginationLinks(pagination, filters, complianceUrl);
    const paginationMeta = buildPaginationMeta(pagination);

    return (
        <AppLayout title="Training & competency">
            <Head title="Training & competency" />

            <PageHeader
                title="Training & competency"
                subtitle={`${curricula.length} curricula · ${summary.overdue_count ?? 0} people overdue for re-certification or first completion.`}
                actions={can.manage && (
                    <div className="flex gap-2">
                        <button type="button" className="btn-secondary text-sm" onClick={() => setCreatingCurriculum((v) => !v)}>
                            New curriculum
                        </button>
                        <button type="button" className="btn-primary text-sm" onClick={() => setRecording((v) => !v)}>
                            Record an outcome
                        </button>
                    </div>
                )}
            />

            <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                {[
                    { label: 'Overdue', value: summary.overdue_count, alarm: true },
                    { label: 'Assessed this year', value: summary.assessed_this_year },
                    { label: 'Attended-only this year', value: summary.attended_only_this_year },
                    { label: 'Awareness campaigns sent this year', value: summary.awareness_campaigns_sent_this_year },
                ].map((t) => (
                    <div key={t.label} className={`rounded-lg border p-4 ${t.alarm && t.value > 0 ? 'border-red-300 bg-red-50' : 'border-gray-200 bg-white'}`}>
                        <p className="text-xs font-medium uppercase tracking-wide text-gray-500">{t.label}</p>
                        <p className="mt-1 font-mono text-2xl text-gray-900">{t.value ?? 0}</p>
                    </div>
                ))}
            </div>

            {curricula.length === 0 ? (
                <p className="mb-6 rounded-lg border border-dashed border-gray-300 p-6 text-sm text-gray-500">
                    No training curricula are configured. The six role-based curricula ship as a content pack —
                    load them from module settings.
                </p>
            ) : (
                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {curricula.map((c) => (
                        <div key={c.id} className="rounded-lg border border-gray-200 bg-white p-4">
                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <p className="font-mono text-xs text-gray-500">{c.code}</p>
                                    <p className="text-sm font-semibold text-gray-900">{c.name}</p>
                                </div>
                                {c.is_mandatory && (
                                    <span className="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-gray-700">Mandatory</span>
                                )}
                            </div>
                            <p className="mt-2 text-xs text-gray-500">Every {c.frequency_months} months</p>
                            <p className={`mt-1 inline-block rounded px-2 py-0.5 text-xs ${c.requires_assessment ? 'bg-emerald-50 text-emerald-800' : 'bg-gray-100 text-gray-700'}`}>
                                {c.requires_assessment ? `Requires assessment, pass ${c.pass_mark}%` : 'Awareness only — no assessment'}
                            </p>
                            <p className="mt-2 text-xs text-gray-600">
                                {(c.target_roles ?? []).includes('*') ? 'Every active user' : (c.target_roles ?? []).join(', ')}
                            </p>
                            <p className="mt-1 text-xs text-gray-500">
                                {c.assigned_count} assigned
                                {c.assigned_count === 0 && ' — check target roles; automatic enrolment from directory groups is not active in this build.'}
                            </p>
                        </div>
                    ))}
                </div>
            )}

            {creatingCurriculum && can.manage && (
                <form onSubmit={submitCurriculum} className="card mb-6">
                    <div className="card-body grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <FormField label="Code" error={create.errors.code}>
                            <input className="form-input" value={create.data.code} onChange={(e) => create.setData('code', e.target.value)} />
                        </FormField>
                        <FormField label="Name" error={create.errors.name}>
                            <input className="form-input" value={create.data.name} onChange={(e) => create.setData('name', e.target.value)} />
                        </FormField>
                        <FormField label="Target roles (comma-separated, or *)" error={create.errors.target_roles}>
                            <input className="form-input" value={create.data.target_roles}
                                onChange={(e) => create.setData('target_roles', e.target.value)} />
                        </FormField>
                        <FormField label="Frequency (months)">
                            <input type="number" min="1" className="form-input" value={create.data.frequency_months}
                                onChange={(e) => create.setData('frequency_months', e.target.value)} />
                        </FormField>
                        <FormField label="Mandatory?">
                            <input type="checkbox" className="form-checkbox" checked={create.data.is_mandatory}
                                onChange={(e) => create.setData('is_mandatory', e.target.checked)} />
                        </FormField>
                        <FormField label="Requires assessment?">
                            <input type="checkbox" className="form-checkbox" checked={create.data.requires_assessment}
                                onChange={(e) => create.setData('requires_assessment', e.target.checked)} />
                        </FormField>
                        {create.data.requires_assessment && (
                            <FormField label="Pass mark (%)" error={create.errors.pass_mark}>
                                <input type="number" min="1" max="100" className="form-input" value={create.data.pass_mark}
                                    onChange={(e) => create.setData('pass_mark', e.target.value)} />
                            </FormField>
                        )}
                        <div className="sm:col-span-3">
                            <button type="submit" className="btn-primary text-sm" disabled={create.processing}>Create curriculum</button>
                        </div>
                    </div>
                </form>
            )}

            {heavyAmberCurricula.map((c) => (
                <div key={c.id} className="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                    More than half of {c.name}'s records are attended but not yet assessed. Clause 7.2 requires an
                    assessed record, not attendance — assessments are outstanding, not complete.
                </div>
            ))}

            <div className="filter-bar mb-4">
                <div className="filter-bar-inner">
                    <div className="filter-group min-w-[180px]">
                        <label className="filter-label" htmlFor="filter-curriculum">Curriculum</label>
                        <select id="filter-curriculum" className="filter-select" value={filters.curriculum_id ?? ''} onChange={(e) => filter('curriculum_id', e.target.value)}>
                            <option value="">Any curriculum</option>
                            {curricula.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                    </div>
                    <div className="filter-group min-w-[160px]">
                        <label className="filter-label" htmlFor="filter-department">Department</label>
                        <select id="filter-department" className="filter-select" value={filters.department ?? ''} onChange={(e) => filter('department', e.target.value)}>
                            <option value="">Any department</option>
                            {departments.map((d) => <option key={d} value={d}>{d}</option>)}
                        </select>
                    </div>
                </div>
            </div>

            {recording && can.manage && (
                <form onSubmit={submitRecord} className="card mb-6">
                    <div className="card-body grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <FormField label="Curriculum" error={record.errors.curriculum_id}>
                            <select className="form-select" value={record.data.curriculum_id}
                                onChange={(e) => record.setData('curriculum_id', e.target.value)}>
                                <option value="">Select…</option>
                                {curricula.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                        </FormField>
                        <FormField label="Person" error={record.errors.user_id}>
                            <select className="form-select" value={record.data.user_id}
                                onChange={(e) => record.setData('user_id', e.target.value)}>
                                <option value="">Select…</option>
                                {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                            </select>
                        </FormField>
                        <FormField label="Completed date" error={record.errors.completed_at}>
                            <input type="date" className="form-input" value={record.data.completed_at}
                                onChange={(e) => record.setData('completed_at', e.target.value)} />
                        </FormField>
                        {selectedCurriculum?.requires_assessment && (
                            <>
                                {/* No assessor picker: the server always records the
                                    acting user (B4) and refuses self-assessment — a
                                    picker here would be a control it silently ignores. */}
                                <FormField label="Assessor">
                                    <p className="py-2 text-sm text-gray-700">Assessed by: {currentUserName ?? '—'}</p>
                                </FormField>
                                <FormField label={`Score (pass mark ${selectedCurriculum.pass_mark}%)`} error={record.errors.score}>
                                    <input type="number" min="0" max="100" className="form-input" value={record.data.score}
                                        onChange={(e) => record.setData('score', e.target.value)} />
                                </FormField>
                            </>
                        )}
                        {flash?.success && (
                            <p className="sm:col-span-3 text-xs text-emerald-700">{flash.success}</p>
                        )}
                        {flash?.error && (
                            <p role="alert" className="sm:col-span-3 text-xs text-red-700">{flash.error}</p>
                        )}
                        <div className="sm:col-span-3">
                            <button type="submit" className="btn-primary text-sm" disabled={record.processing}>Save</button>
                        </div>
                    </div>
                </form>
            )}

            <div className="rounded-lg border border-gray-200 bg-white">
              <div className="overflow-x-auto">
                <table className="data-table">
                    <caption className="sr-only">Training compliance</caption>
                    <thead>
                        <tr>
                            <th scope="col">Person</th>
                            <th scope="col">Department</th>
                            <th scope="col">Curriculum</th>
                            <th scope="col">Attendance</th>
                            <th scope="col">Competency</th>
                            <th scope="col">Next due</th>
                            <th scope="col">Source</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.length === 0 && (
                            <tr><td colSpan={7} className="py-12 text-center text-sm text-gray-500">No records match.</td></tr>
                        )}
                        {rows.map((row, i) => {
                            const overdue = row.attendance.next_due_date && row.attendance.next_due_date < new Date().toISOString().slice(0, 10);
                            const dueSoon = row.attendance.next_due_date && !overdue
                                && new Date(row.attendance.next_due_date) < new Date(Date.now() + 30 * 86400000);

                            return (
                                <tr key={i} className={overdue ? 'bg-red-50' : ''}>
                                    <td className="text-sm text-gray-900">{row.user_name}</td>
                                    <td className="text-sm text-gray-600">{row.department ?? '—'}</td>
                                    <td className="text-sm text-gray-800">{row.curriculum_name}</td>
                                    <td className={`text-sm ${!row.attendance.completed_at ? 'text-red-700' : ''}`}>
                                        {row.attendance.completed_at ? row.attendance.completed_at : 'Not attended'}
                                    </td>
                                    <td className="text-sm">
                                        {!row.requires_assessment ? (
                                            <span className="text-gray-500">Not applicable — awareness only</span>
                                        ) : row.competency?.assessed ? (
                                            <span className={row.competency.score >= row.competency.pass_mark ? 'text-emerald-700' : 'text-amber-700'}>
                                                Assessed {row.competency.score}% by {row.competency.assessor}, {row.competency.assessed_at}
                                                {row.competency.score < row.competency.pass_mark && ` — below the ${row.competency.pass_mark}% pass mark`}
                                            </span>
                                        ) : row.attendance.completed_at ? (
                                            <span className="text-amber-700">
                                                Attended, not yet assessed
                                                {row.assess_url && (
                                                    <button type="button" className="ml-2 text-xs underline"
                                                        onClick={() => setAssessingRow(row)}>Assess now</button>
                                                )}
                                            </span>
                                        ) : (
                                            <span className="text-gray-400">—</span>
                                        )}
                                    </td>
                                    <td className={`text-xs whitespace-nowrap ${overdue ? 'text-red-700' : dueSoon ? 'text-amber-700' : 'text-gray-500'}`}>
                                        {row.attendance.next_due_date ?? '—'}
                                        {overdue && ` — Overdue since ${row.attendance.next_due_date}`}
                                    </td>
                                    <td className="text-xs text-gray-500">
                                        {row.source?.type === 'occurrence' ? 'Linked from an exercise' : 'Manual'}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
              </div>
              <Pagination links={paginationLinks} meta={paginationMeta} />
            </div>

            {assessingRow && (
                <AssessNowForm row={assessingRow} onClose={() => setAssessingRow(null)} />
            )}
        </AppLayout>
    );
}

function AssessNowForm({ row, onClose }) {
    const { flash, auth } = usePage().props;
    const currentUserName = auth?.user?.name ?? null;
    const form = useForm({ score: '' });

    const submit = (e) => {
        e.preventDefault();
        form.post(row.assess_url, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <div role="dialog" aria-modal="true" aria-label={`Assess ${row.user_name}`}
            className="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 p-4">
            <form onSubmit={submit} className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
                <h2 className="text-sm font-semibold text-gray-900">Assess {row.user_name} — {row.curriculum_name}</h2>
                <div className="mt-4 space-y-4">
                    {/* No assessor picker: the server always records the acting
                        user and refuses self-assessment / re-assessment (B4). */}
                    <FormField label="Assessor">
                        <p className="py-2 text-sm text-gray-700">Assessed by: {currentUserName ?? '—'}</p>
                    </FormField>
                    <FormField label="Score (%)" error={form.errors.score}>
                        <input type="number" min="0" max="100" className="form-input" value={form.data.score}
                            onChange={(e) => form.setData('score', e.target.value)} />
                    </FormField>
                    {flash?.error && <p role="alert" className="text-xs text-red-700">{flash.error}</p>}
                </div>
                <div className="mt-6 flex justify-end gap-2">
                    <button type="button" className="btn-secondary text-sm" onClick={onClose}>Cancel</button>
                    <button type="submit" className="btn-primary text-sm" disabled={form.processing}>Save</button>
                </div>
            </form>
        </div>
    );
}

// `TrainingController::compliance()` hand-rolls its own pagination
// (`{current_page,last_page,per_page,total}`), not a Laravel paginator, so
// there is no `links` array with baked-in URLs the way the rest of the
// module's tables carry. These two adapt that shape into the `{links,meta}`
// the shared `Pagination` component expects — every link carries the
// current filters, so a page change never drops them (`preserveScroll`
// happens inside `Pagination` itself via its `Link`).
function paginationHref(filters, page, baseUrl) {
    const params = new URLSearchParams();
    if (filters.curriculum_id) params.set('curriculum_id', filters.curriculum_id);
    if (filters.department) params.set('department', filters.department);
    params.set('page', String(page));
    return `${baseUrl}?${params.toString()}`;
}

function buildPaginationLinks(pagination, filters, baseUrl) {
    if (!pagination) return null;

    const { current_page: current, last_page: last } = pagination;
    const links = [{ url: current > 1 ? paginationHref(filters, current - 1, baseUrl) : null, label: '&laquo; Previous', active: false }];

    for (let page = 1; page <= last; page += 1) {
        links.push({ url: paginationHref(filters, page, baseUrl), label: String(page), active: page === current });
    }

    links.push({ url: current < last ? paginationHref(filters, current + 1, baseUrl) : null, label: 'Next &raquo;', active: false });

    return links;
}

function buildPaginationMeta(pagination) {
    if (!pagination) return null;

    const { current_page: current, per_page: perPage, total } = pagination;

    return {
        from: total === 0 ? 0 : (current - 1) * perPage + 1,
        to: Math.min(current * perPage, total),
        total,
    };
}
