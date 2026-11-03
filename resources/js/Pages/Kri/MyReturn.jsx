import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import ConfirmDialog from '@thirdline/ui/Components/ConfirmDialog';
import EmptyState from '@thirdline/ui/Components/EmptyState';
import Modal from '@thirdline/ui/Components/Modal';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import tryRoute from '@thirdline/ui/lib/tryRoute';
import { BandBadge, ReturnStatusBadge, ValueTag } from '@/Components/Kri/Badges';
import FailureBanner from '@/Components/Kri/FailureBanner';
import Glyph from '@/Components/Kri/Glyph';
import InfoStrip from '@/Components/Kri/InfoStrip';
import KriBandLegend from '@/Components/Kri/KriBandLegend';
import RedBandSection, { emptyRedBand, redBandPayload, useRedBandHost, validateRedBand } from '@/Components/Kri/RedBandSection';
import useMediaQuery from '@/hooks/useMediaQuery';
import useVisitFailures from '@/hooks/useVisitFailures';
import { bandFor, decimalPlaces, describeBand, hasBands } from '@/lib/kri-band';
import { formatDay, formatLagosClock, formatLagosTime, lagosToday, plural } from '@/lib/kri-format';
import { withUnit } from '@/lib/units';

/* ------------------------------------------------------------------ */
/*  State helpers                                                      */
/* ------------------------------------------------------------------ */

function seedLines(lines, ownerOptions, userId) {
    const champion = ownerOptions.some((o) => o.id === userId) ? userId : '';

    return lines.map((l) => ({
        line_id: l.line_id,
        value: l.value ?? l.recorded_value ?? '',
        comment: l.comment ?? '',
        red: emptyRedBand({ ...l.red_band, owner_id: l.red_band?.owner_id ?? (l.open_issue ? '' : champion) }),
    })).map((s) => ({ ...s, value: s.value === '' ? '' : String(s.value) }));
}

function snapshot(state) {
    return JSON.stringify(state.map((s) => [s.line_id, s.value, s.comment, s.red.root_cause, s.red.action_point, s.red.timeline, String(s.red.owner_id ?? ''), s.red.timeline_change_reason]));
}

const filled = (v) => v !== '' && v !== null && v !== undefined && Number.isFinite(Number(v));

const DIRECTION_SENTENCE = {
    higher_worse: 'Higher is worse: the value rising is bad',
    lower_worse: 'Lower is worse: the value falling is bad',
};

/* ------------------------------------------------------------------ */
/*  One KRI                                                            */
/* ------------------------------------------------------------------ */

function LineCard({ index, line, st, setLine, errors, showProblems, readOnly, submitted, periodLabel, ownerOptions, today, onEnter, valueRef, isDesktop, dirty }) {
    const rule = line.bandRule;
    const value = st.value;
    const band = filled(value) ? bandFor(rule, value) : null;
    const isRed = band === 'red';
    const unit = line.kri.unit;
    const prefix = `lines.${index}`;
    const valueId = `value-${line.line_id}`;
    const [thresholdsOpen, setThresholdsOpen] = useState(null);

    const serverRed = {
        root_cause: errors[`${prefix}.red_band.root_cause`],
        action_point: errors[`${prefix}.red_band.action_point`],
        timeline: errors[`${prefix}.red_band.timeline`],
        owner_id: errors[`${prefix}.red_band.owner_id`],
        timeline_change_reason: errors[`${prefix}.red_band.timeline_change_reason`],
    };
    const serverForced = !isRed && Object.values(serverRed).some(Boolean);
    const clientRed = isRed && showProblems ? validateRedBand(st.red, { openIssue: line.open_issue, today }) : {};
    const redErrors = { ...clientRed, ...Object.fromEntries(Object.entries(serverRed).filter(([, v]) => v)) };

    const host = useRedBandHost({
        isRed,
        serverForced,
        messages: {
            enter: `${line.kri.code} is in the red band. Root cause, action point and timeline are now required.`,
            leave: `${line.kri.code} is no longer in the red band. The explanation fields are hidden. What you typed is kept.`,
            forced: 'The server reads this value as red. Explanation required.',
        },
    });

    const tooManyDecimals = decimalPlaces(value) > 6;
    const valueError = errors[`${prefix}.value`]
        ?? (tooManyDecimals ? 'Use at most 6 decimal places.' : (showProblems && !filled(value) ? 'Enter a value.' : null));
    const errorId = `${valueId}-error`;
    const helpId = `${valueId}-help`;
    const bandId = `${valueId}-band`;
    const prefilled = line.value === null && line.recorded_value !== null && line.recorded_value !== undefined;

    const onChange = (e) => setLine(line.line_id, { value: e.target.value });

    const onKeyDown = (e) => {
        if (e.key === 'Enter') {
            // Enter moves on; it never submits (07 section 4.1).
            e.preventDefault();
            if (isRed && host.focusSection()) return;
            onEnter(index);
        }
    };

    const sentence = (() => {
        if (!filled(value) || !hasBands(rule) || (band !== 'red' && band !== 'amber')) return null;

        const match = rule.bands.find((b) => (b.code === 'yellow' ? 'amber' : b.code) === band);

        return match ? `${withUnit(Number(value), unit)} is in the ${band} band: ${describeBand(rule.direction, match, (n) => withUnit(n, unit))}` : null;
    })();

    /* ---------- read-only ---------- */
    if (readOnly) {
        const shown = line.value;
        const showBand = shown !== null && shown !== undefined ? bandFor(rule, shown) : null;
        const hasRedText = Boolean(line.red_band?.root_cause || line.red_band?.action_point || line.red_band?.timeline);

        return (
            <li id={`kri-${line.line_id}`} className="rounded-xl border border-gray-200 bg-white p-5">
                <h2 className="text-base font-semibold text-[#1A365D]"><span className="font-mono">{line.kri.code}</span> {line.kri.name}</h2>
                {line.kri.frequency && line.kri.frequency !== 'monthly' && <span className="text-xs text-gray-700">{line.kri.frequency.charAt(0).toUpperCase() + line.kri.frequency.slice(1)} KRI</span>}
                <p className="mt-2 text-sm text-gray-900">
                    {periodLabel} value:{' '}
                    {shown === null || shown === undefined
                        ? <span className="text-gray-700">No value yet</span>
                        : <><strong>{withUnit(shown, unit)}</strong> <BandBadge band={showBand} className="ml-1" /><ValueTag submitted={submitted} /></>}
                </p>
                {line.comment && <p className="mt-1 whitespace-pre-line text-sm text-gray-800"><span className="font-medium">Comment: </span>{line.comment}</p>}
                {line.previous
                    ? <p className="mt-1 text-sm text-gray-700">{line.previous.period_label}: {withUnit(line.previous.value, unit)} <BandBadge band={line.previous.band} className="ml-1" /></p>
                    : <p className="mt-1 text-sm text-gray-700">No earlier reading.</p>}
                <div className="mt-3 rounded-lg bg-gray-50 p-4">
                    <h3 className="text-xs font-semibold text-gray-800">Thresholds</h3>
                    {hasBands(rule) ? <div className="mt-2"><KriBandLegend rule={rule} unit={unit} variant={isDesktop ? 'inline' : 'stacked'} /></div> : <p className="mt-1 text-xs text-gray-700">No thresholds are in force for {periodLabel}.</p>}
                </div>
                {(showBand === 'red' || hasRedText) && (
                    <RedBandSection
                        idPrefix={`rb-${line.line_id}`}
                        title={`${line.kri.code} is in the red band`}
                        values={emptyRedBand(line.red_band)}
                        onChange={() => {}}
                        ownerOptions={ownerOptions}
                        lagosToday={today}
                        readOnly
                    />
                )}
                {line.open_issue && (
                    <p className="mt-3 text-sm text-gray-800">
                        Open Issue:{' '}
                        {line.open_issue.url
                            ? <Link href={line.open_issue.url} className="font-mono text-[#1A365D] underline">{line.open_issue.reference}</Link>
                            : <span className="font-mono">{line.open_issue.reference}</span>}
                        {line.open_issue.due_date && ` due ${formatDay(line.open_issue.due_date)}`}
                        {line.open_issue.is_overdue && <span className="ml-2 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800">Overdue</span>}
                    </p>
                )}
            </li>
        );
    }

    /* ---------- editable ---------- */
    return (
        <li id={`kri-${line.line_id}`} className="scroll-mt-24 rounded-xl border border-gray-200 bg-white p-5 scroll-mb-40" onFocusCapture={() => setThresholdsOpen((o) => o ?? true)}>
            <h2 className="text-base font-semibold text-[#1A365D]">
                <span className="font-mono">{line.kri.code}</span> {line.kri.name}
                {unit && <span className="ml-2 text-xs font-normal text-gray-700">in {unit}</span>}
                {line.kri.frequency && line.kri.frequency !== 'monthly' && (
                    <span className="ml-2 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-800">{line.kri.frequency.charAt(0).toUpperCase() + line.kri.frequency.slice(1)} KRI</span>
                )}
            </h2>

            {line.kri.description && (
                <details className="mt-2 text-sm text-gray-800">
                    <summary className="cursor-pointer text-[#1A365D] underline">About this KRI</summary>
                    <p className="mt-1 whitespace-pre-line">{line.kri.description}</p>
                    {line.kri.url && <a href={line.kri.url} target="_blank" rel="noopener noreferrer" className="mt-1 inline-block text-[#1A365D] underline">Open the KRI page (opens in a new tab)</a>}
                </details>
            )}

            <p className="mt-2 text-sm text-gray-700">
                {line.previous
                    ? <>{line.previous.period_label}: {withUnit(line.previous.value, unit)} <BandBadge band={line.previous.band} className="ml-1" /></>
                    : 'No earlier reading.'}
            </p>

            <details open={thresholdsOpen ?? isDesktop} onToggle={(e) => { if (e.currentTarget.open !== (thresholdsOpen ?? isDesktop)) setThresholdsOpen(e.currentTarget.open); }} className="mt-3 rounded-lg bg-gray-50 p-4">
                <summary className="cursor-pointer text-xs font-semibold text-gray-800">Thresholds</summary>
                <div className="mt-2 space-y-2 text-sm text-gray-800">
                    <p>{DIRECTION_SENTENCE[rule?.direction] ?? DIRECTION_SENTENCE[String(line.kri.direction).startsWith('lower') ? 'lower_worse' : 'higher_worse']}</p>
                    <p>
                        Green boundary {line.kri.green_boundary === null || line.kri.green_boundary === '' ? 'Not set' : withUnit(line.kri.green_boundary, unit)}
                        {' · '}Red boundary {line.kri.red_boundary === null || line.kri.red_boundary === '' ? 'Not set' : withUnit(line.kri.red_boundary, unit)}
                        {' · '}Target {line.kri.target_value === null ? 'Not set' : withUnit(line.kri.target_value, unit)}
                    </p>
                    {hasBands(rule)
                        ? <KriBandLegend rule={rule} unit={unit} variant={isDesktop ? 'inline' : 'stacked'} />
                        : <p>No thresholds are in force for {periodLabel}. This KRI can&apos;t be banded; your value will be recorded without a band.</p>}
                </div>
            </details>

            <div className="mt-4">
                <label htmlFor={valueId} className="block text-sm font-medium text-gray-900">
                    {periodLabel} value{unit ? ` (${unit})` : ''}
                    <span aria-hidden="true" className="text-red-600"> *</span><span className="sr-only"> (required)</span>
                </label>
                <div className="mt-1 flex flex-wrap items-center gap-3">
                    <input
                        id={valueId}
                        ref={valueRef}
                        type="number"
                        step="any"
                        inputMode="decimal"
                        required
                        aria-required="true"
                        value={value}
                        onChange={onChange}
                        onKeyDown={onKeyDown}
                        onBlur={host.flush}
                        onWheel={(e) => e.currentTarget.blur()}
                        aria-invalid={valueError ? 'true' : undefined}
                        aria-describedby={[bandId, prefilled && value !== '' ? helpId : null, valueError ? errorId : null].filter(Boolean).join(' ')}
                        className="min-h-11 w-48 rounded-lg border border-gray-300 px-3 py-2 text-base focus:border-[#1A365D] focus:ring-2 focus:ring-[#1A365D] sm:text-sm [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                    />
                    <span id={bandId}>
                        {filled(value) ? <BandBadge band={band} /> : <span className="text-sm text-gray-700">Enter a value to see the band</span>}
                        {filled(value) && <ValueTag submitted={false} />}
                    </span>
                </div>
                {sentence && <p className="mt-1 text-sm text-gray-800">{sentence}</p>}
                {prefilled && value !== '' && <p id={helpId} className="mt-1 text-xs text-gray-700">Pre-filled from the reading already recorded for this period ({withUnit(line.recorded_value, unit)}). Submitting records your value as this period&apos;s reading.</p>}
                {valueError && (
                    <p id={errorId} className="mt-1 flex items-start gap-1 text-sm text-red-700"><Glyph name="error" className="!h-4 !w-4" /><span>{valueError}</span></p>
                )}
            </div>

            {host.liveRegion}
            {(isRed || serverForced) && (
                <>
                    {serverForced && <p className="mt-2 text-sm text-red-700">The server reads this value as red: the thresholds may have changed since you opened this page.</p>}
                    <RedBandSection
                        idPrefix={`rb-${line.line_id}`}
                        title={`${line.kri.code} is in the red band: explain it`}
                        context="reading"
                        values={st.red}
                        onChange={(field, v) => setLine(line.line_id, { red: { ...st.red, [field]: v } })}
                        errors={redErrors}
                        openIssue={line.open_issue}
                        ownerOptions={ownerOptions}
                        lagosToday={today}
                        sectionRef={host.sectionRef}
                    />
                </>
            )}

            <div className="mt-4">
                <label htmlFor={`comment-${line.line_id}`} className="block text-sm font-medium text-gray-900">Comment (optional)</label>
                <textarea
                    id={`comment-${line.line_id}`}
                    rows={2}
                    value={st.comment}
                    onChange={(e) => setLine(line.line_id, { comment: e.target.value })}
                    className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base focus:border-[#1A365D] focus:ring-2 focus:ring-[#1A365D] sm:text-sm"
                />
            </div>

            {dirty && <p className="mt-2 text-xs text-gray-700">Edited, not saved</p>}
        </li>
    );
}

/* ------------------------------------------------------------------ */
/*  The screen                                                         */
/* ------------------------------------------------------------------ */

export default function MyReturn(props) {
    const { readOnly, state, departments = [], department, period, opensOn = null, openKriIssues = [], ownerOptions = [], urls = {} } = props;
    const ret = props.return;
    const page = usePage();
    const permissions = page.props.auth?.permissions ?? [];
    const userId = page.props.auth?.user?.id;
    const isDesktop = useMediaQuery('(min-width: 1024px)');
    const today = lagosToday();
    const { failure, clear } = useVisitFailures();

    const [lines, setLines] = useState(() => seedLines(props.lines ?? [], ownerOptions, userId));
    const baseline = useRef(snapshot(lines));
    const [lockVersion, setLockVersion] = useState(ret?.lock_version ?? 0);
    const [errors, setErrors] = useState({});
    const [attempted, setAttempted] = useState(false);
    const [busy, setBusy] = useState(null); // 'draft' | 'submit' | 'reopen'
    const [slow, setSlow] = useState(false);
    const [status, setStatus] = useState(null); // {tone, text}
    const [savedAt, setSavedAt] = useState(null);
    const [confirmSubmit, setConfirmSubmit] = useState(false);
    const [confirmReopen, setConfirmReopen] = useState(false);
    const [reopenReason, setReopenReason] = useState('');
    const [leaveTo, setLeaveTo] = useState(null);
    const [backup, setBackup] = useState(null);
    const [listChange, setListChange] = useState(null);
    const [liveSummary, setLiveSummary] = useState('');
    const [lastAction, setLastAction] = useState(null);
    const [focusSummary, setFocusSummary] = useState(0);
    const refs = useRef({});
    const summaryHeading = useRef(null);
    const statusHeading = useRef(null);
    const bypass = useRef(false);
    const slowTimer = useRef(null);
    const storageKey = ret ? `kri-return-${ret.id}` : null;

    const editable = !readOnly && ret && (ret.can_save || ret.can_submit);
    const submitted = ret?.status === 'submitted';
    const propsLines = props.lines ?? [];

    const snap = snapshot(lines);
    const dirty = editable && snap !== baseline.current;

    const stateOf = (id) => lines.find((l) => l.line_id === id);
    const setLine = useCallback((id, patch) => setLines((prev) => prev.map((l) => (l.line_id === id ? { ...l, ...patch } : l))), []);

    /* ---------- derived counts ---------- */
    const analysis = useMemo(() => propsLines.map((line, i) => {
        const st = lines.find((l) => l.line_id === line.line_id) ?? { value: '', red: emptyRedBand() };
        const hasValue = filled(st.value);
        const band = hasValue ? bandFor(line.bandRule, st.value) : null;
        const redProblems = band === 'red' ? validateRedBand(st.red, { openIssue: line.open_issue, today }) : {};

        return { i, line, st, hasValue, band, red: band === 'red', redProblems, decimals: decimalPlaces(st.value) > 6 };
    }), [propsLines, lines, today]);

    const needValue = analysis.filter((a) => !a.hasValue).length;
    const needExplain = analysis.filter((a) => a.red && Object.keys(a.redProblems).length > 0).length;
    const badDecimals = analysis.filter((a) => a.decimals).length;
    const complete = needValue === 0 && needExplain === 0 && badDecimals === 0;
    const have = analysis.filter((a) => a.hasValue).length;
    const total = props.progress?.total ?? propsLines.length;

    const summaryText = complete
        ? 'Ready to submit'
        : [needValue > 0 ? `${needValue} still ${needValue === 1 ? 'needs' : 'need'} a value` : null, needExplain > 0 ? `${needExplain} red ${needExplain === 1 ? 'needs' : 'need'} an explanation` : null, badDecimals > 0 ? `${badDecimals} ${badDecimals === 1 ? 'has' : 'have'} too many decimal places` : null].filter(Boolean).join(' · ');

    /* ---------- reseeding ---------- */
    const reseed = useCallback((fresh, keepDirty) => {
        const seeded = seedLines(fresh.lines ?? [], fresh.ownerOptions ?? ownerOptions, userId);

        setLines((prev) => {
            if (!keepDirty) return seeded;

            const oldBase = baselineRows.current;
            const byId = new Map(prev.map((p) => [p.line_id, p]));

            return seeded.map((s) => {
                const mine = byId.get(s.line_id);

                if (!mine) return s;

                const base = oldBase.get(s.line_id);

                return base !== undefined && JSON.stringify(rowKey(mine)) !== base ? mine : s;
            });
        });

        const freshIds = new Set(seeded.map((s) => s.line_id));
        const oldIds = new Set(propsLines.map((l) => l.line_id));
        const added = seeded.filter((s) => !oldIds.has(s.line_id)).length;
        const removed = [...oldIds].filter((id) => !freshIds.has(id));

        if (added > 0 || removed.length > 0) {
            setListChange({ added, removed: removed.map((id) => ({ id, kri: propsLines.find((l) => l.line_id === id)?.kri, entry: stateOf(id) })).filter((r) => r.entry && filled(r.entry.value)) , removedCount: removed.length });
        }

        baseline.current = snapshot(seeded);
        baselineRows.current = new Map(seeded.map((s) => [s.line_id, JSON.stringify(rowKey(s))]));
        setLockVersion(fresh.return?.lock_version ?? 0);
    }, [ownerOptions, userId, propsLines]); // eslint-disable-line react-hooks/exhaustive-deps

    const baselineRows = useRef(new Map(lines.map((s) => [s.line_id, JSON.stringify(rowKey(s))])));

    /* ---------- backup in sessionStorage ---------- */
    useEffect(() => {
        if (!storageKey || !editable) return undefined;

        try {
            const raw = sessionStorage.getItem(storageKey);
            const saved = raw ? JSON.parse(raw) : null;

            if (saved && saved.snap !== baseline.current) setBackup(saved);
        } catch { /* storage unavailable: no backup, no harm */ }

        return undefined;
    }, [storageKey]); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        if (!storageKey || !editable || backup) return undefined;

        const t = setTimeout(() => {
            try {
                if (snap === baseline.current) sessionStorage.removeItem(storageKey);
                else sessionStorage.setItem(storageKey, JSON.stringify({ at: new Date().toISOString(), snap: baseline.current, lines }));
            } catch { /* quota or private mode */ }
        }, 1000);

        return () => clearTimeout(t);
    }, [snap, storageKey, editable, backup]); // eslint-disable-line react-hooks/exhaustive-deps

    const clearBackup = () => {
        try { if (storageKey) sessionStorage.removeItem(storageKey); } catch { /* ignore */ }
        setBackup(null);
    };

    /* ---------- leaving with unsaved work ---------- */
    useEffect(() => {
        if (!dirty) return undefined;

        const handler = (event) => {
            event.preventDefault();
            event.returnValue = '';
        };

        window.addEventListener('beforeunload', handler);

        const off = router.on('before', (event) => {
            const visit = event.detail.visit;

            if (bypass.current || visit.method !== 'get' || (visit.only ?? []).length > 0 || visit.preserveState === true) return;

            event.preventDefault();
            setLeaveTo(visit.url.href);
        });

        return () => {
            window.removeEventListener('beforeunload', handler);
            off();
        };
    }, [dirty]);

    /* ---------- focus helpers ---------- */
    const focusFirstIncomplete = (list = analysis) => {
        const target = list.find((a) => !a.hasValue || a.decimals) ?? list.find((a) => a.red && Object.keys(a.redProblems).length > 0);

        if (!target) return;

        const id = !target.hasValue || target.decimals ? `value-${target.line.line_id}` : null;

        requestAnimationFrame(() => {
            const el = id
                ? document.getElementById(id)
                : document.querySelector(`#kri-${target.line.line_id} [data-redband-field][aria-invalid="true"]`) ?? document.querySelector(`#kri-${target.line.line_id} [data-redband-field]`);

            el?.scrollIntoView({ block: 'center' });
            el?.focus();
        });
    };

    const onEnter = (index) => {
        const next = propsLines[index + 1];
        const el = next ? document.getElementById(`value-${next.line_id}`) : document.getElementById('save-draft-button');

        el?.focus();
    };

    /* ---------- requests ---------- */
    const body = () => ({
        lock_version: lockVersion,
        lines: analysis.map((a) => {
            const out = { line_id: a.line.line_id, value: a.hasValue ? Number(a.st.value) : null, comment: a.st.comment === '' ? null : a.st.comment };

            if (a.red) out.red_band = redBandPayload(a.st.red, a.line.open_issue);

            return out;
        }),
    });

    const run = (kind, url, data, { success }) => {
        setBusy(kind);
        setSlow(false);
        setStatus(null);
        setLastAction(kind);
        clear();
        bypass.current = true;
        slowTimer.current = setTimeout(() => setSlow(true), 15000);

        const options = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (p) => {
                const refusal = p.props.flash?.error;

                setErrors({});

                if (refusal) {
                    // Lifecycle refusal (already submitted, period closed...): the page rerenders from the new props.
                    setStatus({ tone: 'warning', text: refusal });
                    reseed(p.props, true);
                } else {
                    reseed(p.props, false);
                    clearBackup();
                    setStatus({ tone: 'success', text: success(p.props) });
                    if (kind === 'draft') setSavedAt(formatLagosClock());
                }

                setAttempted(false);
                requestAnimationFrame(() => (kind === 'draft' ? document.getElementById('save-draft-button') : statusHeading.current)?.focus());
            },
            onError: (errs) => {
                setErrors(errs);
                setConfirmSubmit(false);
                setConfirmReopen(false);

                if (kind === 'submit' || kind === 'draft') setFocusSummary((n) => n + 1);
            },
            onFinish: () => {
                clearTimeout(slowTimer.current);
                bypass.current = false;
                setBusy(null);
                setSlow(false);
                if (kind === 'submit') setConfirmSubmit(false);
                if (kind === 'reopen') setConfirmReopen(false);
            },
        };

        if (kind === 'draft') router.put(url, data, options);
        else router.post(url, data, options);
    };

    const saveDraft = () => run('draft', urls.draft, body(), { success: () => 'Draft saved. Nothing has been submitted yet.' });
    const doSubmit = () => run('submit', urls.submit, body(), { success: () => `Return submitted. ${total} ${plural(total, 'reading')} recorded.` });
    const doReopen = () => run('reopen', urls.reopen, { lock_version: lockVersion, reason: reopenReason }, { success: () => 'Return reopened. It counts as not submitted until you submit it.' });

    const trySubmit = () => {
        setAttempted(true);

        if (!complete) {
            focusFirstIncomplete();

            return;
        }

        setConfirmSubmit(true);
    };

    const reloadKeepingEntries = () => {
        router.reload({
            only: ['return', 'lines', 'progress', 'ownerOptions', 'openKriIssues'],
            preserveScroll: true,
            preserveState: true,
            onSuccess: (p) => {
                reseed(p.props, true);
                setErrors({});
                clear();
            },
        });
    };

    // After a network error on a write the page first learns whether it landed (07 section 4.5).
    const retry = () => {
        clear();

        if (lastAction === 'submit') {
            router.reload({
                only: ['return', 'lines', 'progress'],
                preserveScroll: true,
                preserveState: true,
                onSuccess: (p) => {
                    if (p.props.return?.status === 'submitted') {
                        reseed(p.props, false);
                        clearBackup();
                        setStatus({ tone: 'success', text: `Return submitted. ${total} ${plural(total, 'reading')} recorded.` });
                    } else {
                        doSubmit();
                    }
                },
            });
        } else if (lastAction === 'reopen') doReopen();
        else saveDraft();
    };

    const copyEntries = async () => {
        const text = analysis.filter((a) => a.hasValue).map((a) => `${a.line.kri.code} ${a.line.kri.name}: ${withUnit(a.st.value, a.line.kri.unit)}`).join('\n');

        try { await navigator.clipboard.writeText(text); setStatus({ tone: 'success', text: 'Your entries were copied.' }); } catch { setStatus({ tone: 'warning', text: 'Could not copy automatically. Select the values on the page and copy them.' }); }
    };

    /* ---------- error summary ---------- */
    const problems = [];
    const showProblems = attempted || Object.keys(errors).length > 0;

    if (editable && showProblems) {
        analysis.forEach((a) => {
            const prefix = `lines.${a.i}`;
            const code = a.line.kri.code;
            const push = (message, target) => problems.push({ key: `${a.line.line_id}-${target}-${message}`, code, message, target });

            if (errors[`${prefix}.value`]) push(errors[`${prefix}.value`], `value-${a.line.line_id}`);
            else if (!a.hasValue && attempted) push('Enter a value.', `value-${a.line.line_id}`);
            else if (a.decimals) push('Use at most 6 decimal places.', `value-${a.line.line_id}`);

            ['root_cause', 'action_point', 'timeline', 'owner_id', 'timeline_change_reason'].forEach((f) => {
                const msg = errors[`${prefix}.red_band.${f}`] ?? (a.red && attempted ? a.redProblems[f] : null);

                if (msg) push(msg, `rb-${a.line.line_id}-${f}`);
            });
        });
    }

    useEffect(() => {
        if (focusSummary > 0 && summaryHeading.current) {
            summaryHeading.current.scrollIntoView({ block: 'center' });
            summaryHeading.current.focus();
        }
    }, [focusSummary]);

    const lockError = errors.lock_version;
    const jump = (id) => {
        const el = document.getElementById(id);

        el?.scrollIntoView({ block: 'center' });
        el?.focus();
    };

    /* ---------- page-level states ---------- */
    const kriFilterUrl = permissions.includes('kri.view') && department ? tryRoute('risk.kri.departments.show', department.id) : null;
    const contactsUrl = permissions.includes('kri.manage_department_contacts') ? tryRoute('risk.kri.contacts.index') : null;
    const returnsUrl = permissions.includes('kri.view') ? tryRoute('risk.kri.returns.index') : null;

    const switcher = departments.length > 1 && (
        <nav aria-label="Your departments" className="mb-4 flex flex-wrap gap-2">
            {departments.map((d) => (
                d.id === department?.id
                    ? <span key={d.id} aria-current="page" className="rounded-md bg-[#1A365D] px-3 py-2 text-sm font-medium text-white">{d.name}</span>
                    : <Link key={d.id} href={d.url} className="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-[#1A365D] focus:outline-none focus:ring-2 focus:ring-[#1A365D]">{d.name}</Link>
            ))}
        </nav>
    );

    const title = readOnly ? `${department?.name ?? 'KRI'} return` : 'Complete my KRIs';
    const header = (
        <PageHeader
            title={title}
            subtitle={department && period ? `${department.name} · ${period.label}` : undefined}
            breadcrumbs={readOnly
                ? [{ label: 'KRI Monitoring', href: route('risk.kri.index') }, ...(returnsUrl ? [{ label: 'Returns', href: returnsUrl }] : []), { label: department?.name ?? 'Return' }]
                : [{ label: 'KRI Monitoring', href: route('risk.kri.index') }, { label: 'Complete my KRIs' }]}
        />
    );

    if (state === 'not_configured') {
        return (
            <AuthenticatedLayout title={title}>
                <Head title={title} />
                {header}
                <div className="rounded-xl border border-gray-200 bg-white">
                    <EmptyState
                        title="You aren't set up as a risk champion"
                        description="Ask the Risk Office to add you under Department KRI Contacts. You also need the Submit KRI return permission."
                    />
                    <div className="space-y-1 pb-6 text-center text-sm">
                        {contactsUrl && <p><Link href={contactsUrl} className="font-medium text-[#1A365D] underline">Department KRI contacts</Link></p>}
                        {returnsUrl && <p className="text-gray-800">To see what a champion sees, open a department&apos;s return from <Link href={returnsUrl} className="font-medium text-[#1A365D] underline">KRI returns</Link>.</p>}
                    </div>
                </div>
            </AuthenticatedLayout>
        );
    }

    if (state === 'not_open') {
        return (
            <AuthenticatedLayout title={title}>
                <Head title={title} />
                {header}
                {switcher}
                <div className="rounded-xl border border-gray-200 bg-white">
                    <EmptyState
                        title="This month's return hasn't opened yet"
                        description={`KRI returns open on the first working day of each month. You'll get an email when it does.${opensOn ? ` Expected on ${formatDay(opensOn)}.` : ''}`}
                    />
                    {period && <p className="pb-6 text-center text-sm text-gray-800">Waiting for {period.label}</p>}
                </div>
            </AuthenticatedLayout>
        );
    }

    if (state === 'empty') {
        return (
            <AuthenticatedLayout title={title}>
                <Head title={title} />
                {header}
                {switcher}
                <div className="rounded-xl border border-gray-200 bg-white">
                    <EmptyState
                        title={`No KRIs are due for ${department?.name} in ${period?.label}`}
                        description="A return only includes KRIs that are active, entered by hand (not published by a system) and due that month, so quarterly KRIs appear only in a quarter-end month."
                    />
                    {kriFilterUrl && <p className="pb-6 text-center text-sm"><Link href={kriFilterUrl} className="font-medium text-[#1A365D] underline">See this department&apos;s KRIs</Link></p>}
                </div>
            </AuthenticatedLayout>
        );
    }

    /* ---------- state: ok ---------- */
    const verb = lastAction === 'submit' ? 'submit' : lastAction === 'reopen' ? 'reopen' : 'save your draft';
    let banner = null;

    if (failure && lastAction) {
        if (failure.kind === 'forbidden') banner = { ...failure, message: "You are no longer a risk champion for this department, so this return can't be changed. Your entries are still on this page." };
        else if (failure.kind === 'network') banner = { ...failure, message: `Couldn't ${verb}. Check your connection and try again. Your entries are still on this page.` };
        else banner = failure;
    }

    const displayStatus = ret.display_status;
    const overdue = !submitted && ret.days_overdue;

    return (
        <AuthenticatedLayout title={title}>
            <Head title={title} />
            {header}
            {switcher}

            {readOnly && (
                <InfoStrip className="mb-4">You are viewing this return read-only, as the risk champions see it. Values you see may be drafts that have not been submitted.</InfoStrip>
            )}

            <FailureBanner
                failure={banner}
                onRetry={failure?.kind === 'forbidden' ? copyEntries : retry}
                retryLabel={failure?.kind === 'forbidden' ? 'Copy my entries' : undefined}
                onDismiss={clear}
            />
            {failure?.kind === 'session' && lastAction && (
                <p className="-mt-2 mb-4"><button type="button" onClick={copyEntries} className="text-sm font-medium text-[#1A365D] underline">Copy my entries</button></p>
            )}

            {backup && (
                <InfoStrip role="status" className="mb-4">
                    <p>We kept entries you hadn&apos;t saved from {formatLagosTime(backup.at)} Lagos time.</p>
                    <div className="mt-2 flex gap-3">
                        <button type="button" className="btn-secondary text-sm" onClick={() => { setLines((prev) => prev.map((l) => backup.lines.find((b) => b.line_id === l.line_id) ?? l)); clearBackup(); }}>Restore them</button>
                        <button type="button" className="text-sm font-medium text-[#1A365D] underline" onClick={clearBackup}>Discard</button>
                    </div>
                </InfoStrip>
            )}

            <section aria-labelledby="return-status-title" className="mb-4 rounded-xl border border-gray-200 bg-white p-5">
                <h2 id="return-status-title" className="sr-only">Return status</h2>
                <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-gray-900">
                    <ReturnStatusBadge status={displayStatus} daysOverdue={ret.days_overdue} />
                    <span className="font-semibold">Collects {period.label}</span>
                    <span>Requested {formatDay(ret.requested_on)}</span>
                    <span className="font-semibold">Due {formatDay(ret.due_on)}</span>
                </div>

                <div role="status" className="mt-3 space-y-2 text-sm">
                    {overdue ? <p className="flex items-center gap-2 rounded-lg bg-red-50 px-3 py-2 text-red-800"><Glyph name="warning" />This return is {ret.days_overdue} {plural(ret.days_overdue, 'day')} overdue. It was due {formatDay(ret.due_on)}.</p> : null}
                    {ret.status === 'reopened' && <p className="rounded-lg bg-orange-50 px-3 py-2 text-orange-900">This return was reopened on {formatLagosTime(ret.reopened_at)} Lagos time. It counts as not submitted until you submit it again.</p>}
                    {submitted && (
                        <div className="rounded-lg bg-green-50 px-3 py-2 text-green-900">
                            <p className="flex items-center gap-2"><Glyph name="check_circle" className="text-[#2D7D46]" />Submitted on {formatLagosTime(ret.submitted_at)} Lagos time{ret.submitted_by ? ` by ${ret.submitted_by.name}` : ''}.</p>
                            {ret.period_closed && <p className="mt-1">This reporting period is closed, so the return can&apos;t be reopened.</p>}
                        </div>
                    )}
                </div>

                <div className="mt-4">
                    <p className="text-sm text-gray-900">
                        <strong>{editable ? have : (props.progress?.completed ?? 0)} of {total}</strong> KRIs have a value
                        {dirty && ' (includes entries not saved yet)'}
                    </p>
                    <div className="mt-1 h-2 w-full max-w-md rounded bg-gray-200" aria-hidden="true">
                        <div className="h-2 rounded bg-[#2D7D46]" style={{ width: `${total > 0 ? ((editable ? have : (props.progress?.completed ?? 0)) / total) * 100 : 0}%` }} />
                    </div>
                </div>
            </section>

            <div role="status" className={status ? 'mb-4' : 'sr-only'}>
                {status && (
                    <div className={`rounded-lg border px-4 py-3 text-sm ${status.tone === 'success' ? 'border-green-200 bg-green-50 text-green-900' : 'border-amber-200 bg-amber-50 text-amber-900'}`}>
                        <h2 ref={statusHeading} tabIndex={-1} className="font-semibold focus:outline-none">{status.text}</h2>
                    </div>
                )}
            </div>
            {slow && <p role="status" className="mb-4 text-sm text-gray-800">Still working. Your connection is slow. Don&apos;t close this page.</p>}

            {listChange && (
                <InfoStrip role="status" className="mb-4">
                    <p>The list of KRIs changed: {listChange.added} added, {listChange.removedCount} removed.</p>
                    {listChange.removed.map((r) => <p key={r.id}>Removed: {r.kri?.code} {r.kri?.name}. You had typed {withUnit(r.entry.value, r.kri?.unit)}.</p>)}
                </InfoStrip>
            )}

            {lockError && (
                <InfoStrip tone="error" role="alert" className="mb-4">
                    <p>{lockError}</p>
                    <button type="button" onClick={reloadKeepingEntries} className="btn-secondary mt-2 text-sm">Reload and keep my entries</button>
                </InfoStrip>
            )}

            {editable && !submitted && (
                <>
                    <a href="#save-and-submit" className="sr-only focus:not-sr-only focus:mb-2 focus:inline-block focus:rounded focus:bg-white focus:px-2 focus:py-1 focus:text-[#1A365D] focus:underline">Go to Save and Submit</a>
                    <nav aria-label="What needs attention" className="mb-4 text-sm text-gray-900">
                        {complete ? 'Everything is filled in. You can submit.' : (
                            <>
                                {needValue > 0 && <button type="button" className="font-medium text-[#1A365D] underline" onClick={() => focusFirstIncomplete(analysis.filter((a) => !a.hasValue))}>{needValue} need a value</button>}
                                {needValue > 0 && needExplain > 0 && ' · '}
                                {needExplain > 0 && <button type="button" className="font-medium text-[#1A365D] underline" onClick={() => focusFirstIncomplete(analysis.filter((a) => a.red && Object.keys(a.redProblems).length > 0))}>{needExplain} red need an explanation</button>}
                                {needValue === 0 && needExplain === 0 && badDecimals > 0 && `${badDecimals} have too many decimal places`}
                            </>
                        )}
                    </nav>
                </>
            )}

            {openKriIssues.length > 0 && (
                <details className="mb-4 rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-900">
                    <summary className="cursor-pointer font-semibold text-[#1A365D]">
                        Open KRI issues for this department ({openKriIssues.length})
                        {openKriIssues.some((i) => i.is_overdue) && <span className="ml-2 font-semibold text-red-700">{openKriIssues.filter((i) => i.is_overdue).length} overdue</span>}
                    </summary>
                    <ul className="mt-2 divide-y divide-gray-100">
                        {openKriIssues.map((issue) => (
                            <li key={issue.id} className="flex flex-wrap items-center gap-x-3 py-2">
                                {issue.url
                                    ? <a href={issue.url} target="_blank" rel="noopener noreferrer" className="font-mono text-[#1A365D] underline">{issue.reference}<span className="sr-only"> (opens in a new tab)</span></a>
                                    : <span className="font-mono">{issue.reference}</span>}
                                <span>{issue.title}</span>
                                <span className="text-gray-700">{String(issue.status ?? '').replace(/_/g, ' ').toLowerCase()}</span>
                                {issue.due_date && <span>Due {formatDay(issue.due_date)}</span>}
                                {issue.is_overdue && <span className="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800">Overdue</span>}
                            </li>
                        ))}
                    </ul>
                </details>
            )}

            {problems.length > 0 && (
                <section className="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900">
                    <h2 ref={summaryHeading} tabIndex={-1} className="font-semibold focus:outline-none">Fix {problems.length} {problems.length === 1 ? 'item' : 'items'} before submitting</h2>
                    <p className="mt-1">Nothing was submitted. Your entries are still here.</p>
                    <ul className="mt-2 list-disc space-y-1 pl-5">
                        {problems.map((p) => <li key={p.key}>{p.code}: <a href={`#${p.target}`} onClick={(e) => { e.preventDefault(); jump(p.target); }} className="underline">{p.message}</a></li>)}
                    </ul>
                </section>
            )}

            <ol aria-label={`KRIs due for ${period.label}`} className="space-y-4 pb-4" onBlur={() => setLiveSummary(summaryText)}>
                {propsLines.map((line, i) => (
                    <LineCard
                        key={line.line_id}
                        index={i}
                        line={line}
                        st={stateOf(line.line_id) ?? { value: '', comment: '', red: emptyRedBand() }}
                        setLine={setLine}
                        errors={errors}
                        showProblems={showProblems}
                        readOnly={!editable || submitted}
                        submitted={submitted}
                        periodLabel={period.label}
                        ownerOptions={ownerOptions}
                        today={today}
                        onEnter={onEnter}
                        valueRef={(el) => { refs.current[line.line_id] = el; }}
                        isDesktop={isDesktop}
                        dirty={editable && JSON.stringify(rowKey(stateOf(line.line_id) ?? {})) !== baselineRows.current.get(line.line_id)}
                    />
                ))}
            </ol>

            {editable && (
                <div id="save-and-submit" role="region" aria-label="Save and submit" tabIndex={-1} className="sticky bottom-0 z-10 -mx-4 border-t border-gray-200 bg-white px-4 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-3 shadow-[0_-2px_6px_rgba(0,0,0,0.05)] [@media(max-height:400px)]:static">
                    <p id="footer-summary" className="mb-2 text-sm text-gray-900">
                        {submitted ? 'Submitted' : summaryText}{dirty && ' · unsaved changes'}{savedAt && !dirty && ` · Draft saved ${savedAt}`}
                    </p>
                    <span role="status" className="sr-only">{liveSummary}</span>
                    <div className="flex gap-3">
                        {ret.can_save && !submitted && (
                            <button id="save-draft-button" type="button" onClick={saveDraft} disabled={busy !== null} aria-busy={busy === 'draft'} className="btn-secondary min-h-11 flex-1 text-sm sm:flex-none">
                                {busy === 'draft' ? 'Saving…' : 'Save draft'}
                            </button>
                        )}
                        {ret.can_submit && !submitted && (
                            <button type="button" onClick={trySubmit} disabled={busy !== null} aria-disabled={!complete} aria-describedby="footer-summary" aria-busy={busy === 'submit'} className={`btn-primary min-h-11 flex-1 text-sm sm:flex-none ${complete ? '' : 'opacity-60'}`}>
                                {busy === 'submit' ? 'Submitting…' : 'Submit return'}
                            </button>
                        )}
                    </div>
                </div>
            )}

            {!readOnly && submitted && ret.can_reopen && (
                <div role="region" aria-label="Reopen" className="sticky bottom-0 z-10 -mx-4 border-t border-gray-200 bg-white px-4 py-3">
                    <button type="button" onClick={() => setConfirmReopen(true)} disabled={busy !== null} className="btn-secondary min-h-11 text-sm">Reopen to update</button>
                </div>
            )}

            {/* Submit */}
            <Modal show={confirmSubmit} maxWidth="lg" onClose={() => { if (busy !== 'submit') setConfirmSubmit(false); }} closeable={busy !== 'submit'}>
                <div className="p-6 text-sm text-gray-900">
                    <h2 className="text-lg font-semibold">Submit {department?.name}&apos;s return for {period.label}?</h2>
                    <ul className="mt-3 list-disc space-y-1 pl-5">
                        <li>{total} {plural(total, 'reading')} will be recorded.</li>
                        <li>
                            {analysis.filter((a) => a.band === 'green').length} green, {analysis.filter((a) => a.band === 'amber').length} amber, {analysis.filter((a) => a.band === 'red').length} red, {analysis.filter((a) => a.band === null).length} without a band
                        </li>
                        {analysis.some((a) => a.red) && (
                            <li>
                                {analysis.filter((a) => a.red).length} red {plural(analysis.filter((a) => a.red).length, 'KRI')}: {analysis.filter((a) => a.red && !a.line.open_issue).length} will raise a new Issue, {analysis.filter((a) => a.red && a.line.open_issue).length} will update the existing open Issue. An Issue is raised at high priority.
                            </li>
                        )}
                        {!ret.period_closed && <li>Submitting locks the return. You can reopen it until {period.label} closes.</li>}
                    </ul>
                    <p className="mt-3 text-gray-700">Nothing is recorded until you press Submit return.</p>
                    {slow && <p role="status" className="mt-2">Still working. Your connection is slow. Don&apos;t close this page.</p>}
                    <div className="mt-6 flex justify-end gap-3">
                        <button type="button" data-autofocus onClick={() => setConfirmSubmit(false)} disabled={busy === 'submit'} className="btn-secondary min-h-11 text-sm">Cancel</button>
                        <button type="button" onClick={doSubmit} disabled={busy === 'submit'} aria-busy={busy === 'submit'} className="btn-primary min-h-11 text-sm">{busy === 'submit' ? 'Submitting…' : 'Submit return'}</button>
                    </div>
                </div>
            </Modal>

            {/* Reopen: needs a reason (A1.10), so it is a Modal, not a ConfirmDialog */}
            <Modal show={confirmReopen} maxWidth="lg" onClose={() => { if (busy !== 'reopen') setConfirmReopen(false); }} closeable={busy !== 'reopen'}>
                <div className="p-6 text-sm text-gray-900">
                    <h2 className="text-lg font-semibold">Reopen this return?</h2>
                    <p className="mt-2">{department?.name}&apos;s return for {period.label} will count as <strong>not submitted</strong> until you submit it again. Changes you make are recorded with the old and new values.</p>
                    <label htmlFor="reopen-reason" className="mt-4 block text-sm font-medium">Reason (10 to 500 characters) <span aria-hidden="true" className="text-red-600">*</span><span className="sr-only">(required)</span></label>
                    <textarea
                        id="reopen-reason"
                        rows={3}
                        maxLength={500}
                        value={reopenReason}
                        onChange={(e) => setReopenReason(e.target.value)}
                        aria-invalid={errors.reason ? 'true' : undefined}
                        aria-describedby={errors.reason ? 'reopen-reason-error' : undefined}
                        className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base focus:border-[#1A365D] focus:ring-2 focus:ring-[#1A365D] sm:text-sm"
                    />
                    {errors.reason && <p id="reopen-reason-error" className="mt-1 text-red-700">{errors.reason}</p>}
                    {errors.lock_version && <p role="alert" className="mt-1 text-red-700">{errors.lock_version}</p>}
                    <div className="mt-6 flex justify-end gap-3">
                        <button type="button" data-autofocus onClick={() => setConfirmReopen(false)} disabled={busy === 'reopen'} className="btn-secondary min-h-11 text-sm">Cancel</button>
                        <button type="button" onClick={doReopen} disabled={busy === 'reopen' || reopenReason.trim().length < 10} aria-busy={busy === 'reopen'} className="btn-primary min-h-11 text-sm">{busy === 'reopen' ? 'Reopening…' : 'Reopen'}</button>
                    </div>
                </div>
            </Modal>

            {/* Leaving with unsaved work */}
            <ConfirmDialog
                show={leaveTo !== null}
                title="Leave without saving?"
                message="You have changes that aren't saved. Leave without saving?"
                confirmLabel="Leave"
                cancelLabel="Stay"
                variant="warning"
                onCancel={() => setLeaveTo(null)}
                onConfirm={() => { const to = leaveTo; bypass.current = true; setLeaveTo(null); router.visit(to); }}
            />
        </AuthenticatedLayout>
    );
}

function rowKey(s) {
    return [s.line_id, s.value, s.comment, s.red?.root_cause, s.red?.action_point, s.red?.timeline, String(s.red?.owner_id ?? ''), s.red?.timeline_change_reason];
}
