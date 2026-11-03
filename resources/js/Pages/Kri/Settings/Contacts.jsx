import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Head, router, useRemember } from '@inertiajs/react';
import { Combobox, ComboboxInput, ComboboxOption, ComboboxOptions } from '@headlessui/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import ConfirmDialog from '@thirdline/ui/Components/ConfirmDialog';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import Modal from '@thirdline/ui/Components/Modal';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import FailureBanner from '@/Components/Kri/FailureBanner';
import Glyph from '@/Components/Kri/Glyph';
import InfoStrip from '@/Components/Kri/InfoStrip';
import useVisitFailures from '@/hooks/useVisitFailures';
import { formatDay } from '@/lib/kri-format';

const HELP = {
    risk_champion: 'Receives the monthly request and every reminder. Completes the return.',
    team_lead: 'Added from the second reminder.',
    unit_head: 'Added from the third reminder.',
    group_head: 'Added from the fourth reminder.',
};
const INDENT = ['ml-0', 'ml-6', 'ml-12', 'ml-[4.5rem]'];
const COUNT_LABEL = { risk_champion: 'Champions', team_lead: 'Team leads', unit_head: 'Unit heads', group_head: 'Group heads' };

/** Flags are words and an icon, never blocking (docs/ui/kri-cycle/05 section 3.5). */
function flagsFor(contact, role, department) {
    const flags = [];

    if (!contact.is_active) flags.push('Account inactive. Emails to this person are skipped.');
    if (contact.directory?.account_enabled === false) flags.push('Disabled in the directory. They still receive emails until their ERM account is made inactive.');
    if (contact.department_mismatch) flags.push(`Directory says: ${contact.directory?.department ?? 'a different department'}. This department is ${department.name}. Shown as a hint; nothing is changed.`);
    if (role === 'risk_champion' && !contact.has_submit_permission) flags.push("Doesn't have the Submit KRI return permission, so they can't open Complete my KRIs.");

    return flags;
}

function ContactRow({ contact, role, roleLabel, department, onRemove }) {
    const flags = flagsFor(contact, role, department);

    return (
        <li className="flex flex-col gap-2 py-3 sm:flex-row sm:items-start sm:justify-between">
            <div className="min-w-0 text-sm">
                <p className="font-medium text-gray-900">{contact.name}</p>
                <p className="break-all text-xs text-gray-600">{contact.email}</p>
                {flags.map((f) => <p key={f} className="mt-1 flex items-start gap-1 text-xs text-amber-800"><Glyph name="warning" className="!h-4 !w-4" /><span>{f}</span></p>)}
                {contact.directory === null
                    ? <p className="mt-1 text-xs text-gray-600">Not in the directory</p>
                    : contact.directory.last_synced_at && <p className="mt-1 text-xs text-gray-600">Directory data from {formatDay(contact.directory.last_synced_at.slice(0, 10))}</p>}
            </div>
            <button
                type="button"
                onClick={() => onRemove(contact)}
                aria-label={`Remove ${contact.name} as ${roleLabel} for ${department.name}`}
                className="btn-secondary min-h-11 w-full text-sm sm:min-h-0 sm:w-auto"
            >
                Remove
            </button>
        </li>
    );
}

/* ---------------- the picker ---------------- */

function AddDialog({ target, onClose, roles }) {
    const { department, role } = target;
    const roleLabel = roles.find((r) => r.value === role)?.label ?? role;
    const [q, setQ] = useState('');
    const [results, setResults] = useState([]);
    const [phase, setPhase] = useState('idle'); // idle | loading | done | error
    const [added, setAdded] = useState(null);
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);
    const [announce, setAnnounce] = useState('');
    const [resetKey, setResetKey] = useState(0);
    const [attempt, setAttempt] = useState(0);
    const cache = useRef(new Map());
    const live = useMemo(() => department.contacts[role] ?? [], [department, role]);
    const taken = useMemo(() => new Set(live.map((c) => c.user_id)), [live]);

    useEffect(() => {
        const text = q.trim();

        if (text.length < 2) {
            setPhase('idle');
            setResults([]);

            return undefined;
        }

        if (cache.current.has(text)) {
            setResults(cache.current.get(text));
            setPhase('done');

            return undefined;
        }

        setPhase('loading');
        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(route('risk.kri.contacts.users', { q: text }), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', signal: controller.signal })
                .then((res) => {
                    if (!res.ok) throw new Error(String(res.status));

                    return res.json();
                })
                .then((list) => {
                    cache.current.set(text, list);
                    setResults(list);
                    setPhase('done');
                    setAnnounce(`${list.length} ${list.length === 1 ? 'person' : 'people'} found`);
                })
                .catch((e) => { if (e.name !== 'AbortError') setPhase('error'); });
        }, 300);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [q, attempt]);

    const pick = (person) => {
        if (!person || taken.has(person.id)) return;

        setSaving(true);
        setError(null);
        router.post(route('risk.kri.contacts.store'), { business_unit_id: department.id, user_id: person.id, role }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setAdded(`Added ${person.name} as ${roleLabel}.`);
                setQ('');
                setResetKey((k) => k + 1);
            },
            onError: (errs) => setError(Object.values(errs)[0] ?? "Couldn't save. Nothing was changed. Try again."),
            onFinish: () => setSaving(false),
        });
    };

    const noDirectory = phase === 'done' && results.length > 0 && results.every((r) => r.directory === null);

    return (
        <Modal show maxWidth="lg" onClose={onClose}>
            <div className="p-6">
                <h2 className="text-lg font-semibold text-gray-900">Add {roleLabel} for {department.name}</h2>

                <div className="mt-4">
                    <Combobox key={resetKey} value={null} onChange={pick} immediate>
                        <label htmlFor="contact-search" className="block text-sm font-medium text-gray-900">Find a person</label>
                        <p id="contact-search-help" className="text-xs text-gray-700">Search by name or email. Type at least 2 characters.</p>
                        <ComboboxInput
                            id="contact-search"
                            type="search"
                            autoComplete="off"
                            autoCapitalize="none"
                            data-autofocus
                            aria-describedby="contact-search-help"
                            displayValue={() => q}
                            onChange={(e) => setQ(e.target.value)}
                            className="mt-1 min-h-11 w-full rounded-lg border border-gray-300 px-3 py-2 text-base focus:border-[#1A365D] focus:ring-2 focus:ring-[#1A365D] sm:text-sm"
                        />
                        <ComboboxOptions static={phase === 'done' && results.length > 0} aria-busy={phase === 'loading'} className="mt-2 max-h-72 divide-y divide-gray-100 overflow-y-auto rounded-lg border border-gray-200 bg-white empty:hidden">
                            {results.map((person) => {
                                const already = taken.has(person.id);
                                const member = person.home_unit?.id === department.id;

                                return (
                                    <ComboboxOption key={person.id} value={person} disabled={already || saving} className="cursor-pointer px-3 py-2 text-sm data-[focus]:bg-blue-50 data-[disabled]:cursor-not-allowed data-[disabled]:opacity-70">
                                        <span className="block font-semibold text-gray-900">{person.name}</span>
                                        <span className="block break-all text-xs text-gray-700">{person.email}</span>
                                        <span className="block text-xs text-gray-700">
                                            {member ? 'Member of this department' : (person.home_unit ? `Home department: ${person.home_unit.name}` : 'No home department')}
                                            {' · '}
                                            {person.directory ? `Directory: ${person.directory.department ?? 'no department'}` : 'Not in the directory'}
                                        </span>
                                        {!person.is_active && <span className="block text-xs text-amber-800">Inactive: emails will be skipped</span>}
                                        {role === 'risk_champion' && !person.has_submit_permission && <span className="block text-xs text-amber-800">Can&apos;t complete returns yet</span>}
                                        {already && <span className="block text-xs font-medium text-gray-800">Already a {roleLabel} here</span>}
                                    </ComboboxOption>
                                );
                            })}
                        </ComboboxOptions>
                    </Combobox>

                    <div aria-live="polite" className="mt-2 text-sm text-gray-700">
                        {phase === 'idle' && 'Type at least 2 characters to search.'}
                        {phase === 'loading' && 'Searching…'}
                        {phase === 'done' && results.length === 0 && `No one matches ‘${q.trim()}’. Only users who exist in ERM can be chosen.`}
                        {phase === 'done' && results.length >= 20 && 'Showing the first 20. Type more to narrow the list.'}
                    </div>
                    {phase === 'error' && (
                        <p role="alert" className="mt-2 text-sm text-red-700">Search failed. <button type="button" className="underline" onClick={() => { cache.current.delete(q.trim()); setAttempt((n) => n + 1); }}>Try again</button></p>
                    )}
                    {noDirectory && <InfoStrip className="mt-2">No directory information is available for these people. You can still choose them.</InfoStrip>}
                    {error && <p role="alert" className="mt-2 text-sm text-red-700">{error}</p>}
                    <div role="status" className="sr-only">{announce}</div>
                    <p role="status" className="mt-2 text-sm font-medium text-green-800">{added}</p>
                    {saving && <p className="mt-1 text-sm text-gray-700">Saving&hellip;</p>}
                </div>

                <div className="mt-6 flex justify-end">
                    <button type="button" onClick={onClose} className="btn-primary min-h-11 text-sm">Done</button>
                </div>
            </div>
        </Modal>
    );
}

/* ---------------- one department ---------------- */

function Department({ department, depth, open, onToggle, roles, onAdd, onRemove, flagged }) {
    const bodyId = `dept-body-${department.id}`;
    const headId = `dept-head-${department.id}`;
    const [everOpen, setEverOpen] = useState(open); // bodies render on first expand

    useEffect(() => { if (open) setEverOpen(true); }, [open]);

    return (
        <li className={INDENT[Math.min(depth, 3)]}>
            <div className="rounded-xl border border-gray-200 bg-white">
                <h2>
                    <button
                        type="button"
                        id={headId}
                        aria-expanded={open}
                        aria-controls={bodyId}
                        onClick={() => onToggle(department.id)}
                        className="flex w-full flex-col gap-1 rounded-xl px-4 py-3 text-left focus:outline-none focus:ring-2 focus:ring-[#1A365D] sm:flex-row sm:items-center sm:justify-between"
                    >
                        <span>
                            <span className="font-semibold text-[#1A365D]">{department.name}</span>
                            {department.code && <span className="ml-2 font-mono text-xs text-gray-600">{department.code}</span>}
                        </span>
                        <span className="text-xs text-gray-700">
                            {roles.map((r, i) => <span key={r.value}>{i > 0 && ' · '}{COUNT_LABEL[r.value]} {department.contacts[r.value].length}</span>)}
                        </span>
                        <span className="flex flex-wrap gap-x-3 text-xs font-medium text-amber-800">
                            {!department.has_champion && <span className="inline-flex items-center gap-1"><Glyph name="warning" className="!h-4 !w-4" />No risk champion</span>}
                            {flagged > 0 && <span className="inline-flex items-center gap-1"><Glyph name="flag" className="!h-4 !w-4" />{flagged} flagged</span>}
                        </span>
                    </button>
                </h2>

                {everOpen && (
                    <div id={bodyId} role="region" aria-labelledby={headId} hidden={!open} className="border-t border-gray-100 px-4 pb-4">
                        {roles.map((r) => {
                            const people = department.contacts[r.value] ?? [];

                            return (
                                <div key={r.value} className="mt-4">
                                    <h3 className="text-sm font-semibold text-gray-900">{r.label}</h3>
                                    <p className="text-xs text-gray-700">{HELP[r.value]}</p>
                                    {people.length === 0 ? (
                                        <p className={`mt-2 flex items-center gap-1 text-sm ${r.value === 'risk_champion' ? 'text-amber-800' : 'text-gray-700'}`}>
                                            {r.value === 'risk_champion' && <Glyph name="warning" className="!h-4 !w-4" />}
                                            {r.value === 'risk_champion' ? 'Nobody set. The monthly request will reach nobody.' : 'Nobody set.'}
                                        </p>
                                    ) : (
                                        <ul className="divide-y divide-gray-100">
                                            {people.map((c) => <ContactRow key={c.contact_id} contact={c} role={r.value} roleLabel={r.label} department={department} onRemove={(contact) => onRemove({ contact, department, role: r })} />)}
                                        </ul>
                                    )}
                                    <button id={`add-${department.id}-${r.value}`} type="button" onClick={() => onAdd({ department, role: r.value })} className="btn-secondary mt-2 min-h-11 text-sm sm:min-h-0">
                                        Add {r.label}
                                    </button>
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
        </li>
    );
}

export default function Contacts({ departments = [], roles = [] }) {
    const { failure, clear } = useVisitFailures();
    const [search, setSearch] = useState('');
    const [onlyNoChampion, setOnlyNoChampion] = useState(false);
    const [onlyFlagged, setOnlyFlagged] = useState(false);
    const [openIds, setOpenIds] = useRemember([], 'kri-contacts-open');
    const [adding, setAdding] = useState(null);
    const [removing, setRemoving] = useState(null);
    const [removeBusy, setRemoveBusy] = useState(false);
    const [removeError, setRemoveError] = useState(false);
    const [announce, setAnnounce] = useState('');

    const flaggedCount = useCallback((d) => roles.reduce((n, r) => n + (d.contacts[r.value] ?? []).filter((c) => flagsFor(c, r.value, d).length > 0).length, 0), [roles]);

    const shown = useMemo(() => {
        const q = search.trim().toLowerCase();

        return departments.filter((d) => (
            (!q || d.name.toLowerCase().includes(q) || (d.code ?? '').toLowerCase().includes(q))
            && (!onlyNoChampion || !d.has_champion)
            && (!onlyFlagged || flaggedCount(d) > 0)
        ));
    }, [departments, search, onlyNoChampion, onlyFlagged, flaggedCount]);

    // Indent under a listed parent; orphans (parent not listed) sit at the top level.
    const ordered = useMemo(() => {
        const ids = new Set(shown.map((d) => d.id));
        const children = new Map();

        shown.forEach((d) => {
            const key = d.parent_id && ids.has(d.parent_id) ? d.parent_id : 0;

            children.set(key, [...(children.get(key) ?? []), d]);
        });

        const out = [];
        const walk = (key, depth) => (children.get(key) ?? []).sort((a, b) => a.name.localeCompare(b.name)).forEach((d) => { out.push({ d, depth }); walk(d.id, depth + 1); });

        walk(0, 0);

        return out;
    }, [shown]);

    useEffect(() => {
        const t = setTimeout(() => setAnnounce(`Showing ${shown.length} of ${departments.length} departments`), 400);

        return () => clearTimeout(t);
    }, [shown.length, departments.length]);

    const toggle = (id) => setOpenIds((ids) => (ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id]));
    const anyChampion = departments.some((d) => d.has_champion);
    const filtering = search.trim() !== '' || onlyNoChampion || onlyFlagged;

    // The department object the dialog holds must follow the refreshed prop, so "Already a ..." stays true.
    const freshAdding = adding ? { ...adding, department: departments.find((d) => d.id === adding.department.id) ?? adding.department } : null;

    const remove = () => {
        const { contact, department, role } = removing;

        setRemoveBusy(true);
        setRemoveError(false);
        router.delete(route('risk.kri.contacts.destroy', contact.contact_id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setRemoving(null);
                requestAnimationFrame(() => document.getElementById(`add-${department.id}-${role.value}`)?.focus());
            },
            onError: () => { setRemoving(null); setRemoveError(true); },
            onFinish: () => setRemoveBusy(false),
        });
    };

    // A 404 (already removed) or a lost response: learn the truth before offering a retry.
    useEffect(() => {
        if (failure?.kind === 'notfound' || failure?.kind === 'network') {
            setRemoving(null);
            router.reload({ only: ['departments'], preserveScroll: true, preserveState: true });
        }
    }, [failure]);

    return (
        <AuthenticatedLayout title="Department KRI Contacts">
            <Head title="Department KRI Contacts" />

            <PageHeader
                title="Department KRI Contacts"
                subtitle="Who receives each department's monthly KRI request and its reminders"
                breadcrumbs={[{ label: 'KRI Monitoring', href: route('risk.kri.index') }, { label: 'Department Contacts' }]}
            />

            <InfoStrip className="mb-4">
                <p>Choosing a risk champion doesn&apos;t give them access. To complete a return they also need the <strong>Submit KRI return</strong> permission, which the Risk Owner role has. People who lack it are flagged below.</p>
                <p className="mt-1">Contacts are chosen from users in ERM. The directory department, where we have it, is a hint only; it is never applied automatically.</p>
            </InfoStrip>

            <FailureBanner failure={failure} onRetry={failure ? () => { clear(); router.reload({ only: ['departments'], preserveScroll: true }); } : undefined} onDismiss={clear} />
            {removeError && (
                <InfoStrip tone="error" role="alert" className="mb-4">Couldn&apos;t save. Nothing was changed. Try again.</InfoStrip>
            )}

            {departments.length === 0 ? (
                <div className="rounded-xl border border-gray-200 bg-white">
                    <EmptyState title="No departments yet" description="Departments come from your organisation's business units, which are set up outside this screen." />
                </div>
            ) : (
                <>
                    {!anyChampion && <InfoStrip tone="warning" className="mb-4">No department has a risk champion yet. Open a department, choose <strong>Add Risk champion</strong> and pick a person.</InfoStrip>}

                    <div className="mb-4 flex flex-col gap-3 md:flex-row md:flex-wrap md:items-end">
                        <div role="search" className="md:w-72">
                            <label htmlFor="contacts-find" className="mb-1 block text-xs font-medium text-gray-700">Find a department</label>
                            <input id="contacts-find" type="search" autoComplete="off" value={search} onChange={(e) => setSearch(e.target.value)} className="min-h-11 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-[#1A365D] focus:ring-2 focus:ring-[#1A365D]" />
                        </div>
                        <label className="flex min-h-11 items-center gap-2 text-sm text-gray-900">
                            <input type="checkbox" checked={onlyNoChampion} onChange={(e) => setOnlyNoChampion(e.target.checked)} className="h-5 w-5 rounded border-gray-500 text-[#1A365D] focus:ring-2 focus:ring-[#1A365D]" />
                            Only departments with no risk champion
                        </label>
                        <label className="flex min-h-11 items-center gap-2 text-sm text-gray-900">
                            <input type="checkbox" checked={onlyFlagged} onChange={(e) => setOnlyFlagged(e.target.checked)} className="h-5 w-5 rounded border-gray-500 text-[#1A365D] focus:ring-2 focus:ring-[#1A365D]" />
                            Only departments with a flagged contact
                        </label>
                        <p role="status" className="text-sm text-gray-700">{announce}</p>
                        {filtering && <p className="text-sm text-gray-800">Showing {shown.length} of {departments.length} departments</p>}
                    </div>

                    {shown.length === 0 ? (
                        <div role="status" className="flex flex-wrap items-center gap-3 text-sm text-gray-800">
                            <span>No department matches your filters.</span>
                            <button type="button" className="btn-secondary text-sm" onClick={() => { setSearch(''); setOnlyNoChampion(false); setOnlyFlagged(false); }}>Clear filters</button>
                        </div>
                    ) : (
                        <ul className="space-y-3">
                            {ordered.map(({ d, depth }) => (
                                <Department
                                    key={d.id}
                                    department={d}
                                    depth={depth}
                                    open={openIds.includes(d.id)}
                                    onToggle={toggle}
                                    roles={roles}
                                    onAdd={setAdding}
                                    onRemove={setRemoving}
                                    flagged={flaggedCount(d)}
                                />
                            ))}
                        </ul>
                    )}
                </>
            )}

            {freshAdding && <AddDialog target={freshAdding} roles={roles} onClose={() => setAdding(null)} />}

            <ConfirmDialog
                show={removing !== null}
                title={removing ? `Remove ${removing.contact.name}?` : 'Remove'}
                message={removing ? `${removing.contact.name} will no longer be a ${removing.role.label} for ${removing.department.name}. They stop receiving its KRI emails${removing.role.value === 'risk_champion' ? ', and can no longer open its return in Complete my KRIs (champions only)' : ''}. The change is recorded in the audit trail. Emails already sent are not affected.${removing.role.value === 'risk_champion' && removing.department.contacts.risk_champion.length === 1 ? ` ${removing.department.name} will have no risk champion, so its monthly request will reach nobody until you add one.` : ''}` : ''}
                confirmLabel="Remove"
                variant="warning"
                processing={removeBusy}
                onCancel={() => setRemoving(null)}
                onConfirm={remove}
            />
        </AuthenticatedLayout>
    );
}
