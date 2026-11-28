import { Link } from '@inertiajs/react';
import InputError from '@thirdline/ui/Components/InputError';

/**
 * Normalise form data before POST / PUT so nullable FKs and optional strings
 * submit as `null` rather than empty strings, and `parent_id` is always cleared
 * when `level === 'process'` (ADR 0028 Decision 3).
 */
export function cleanProcessPayload(data, { includeEntityId = false } = {}) {
    const isSubProcess = data.level === 'sub_process';

    const payload = {
        level: isSubProcess ? 'sub_process' : 'process',
        parent_id: isSubProcess && data.parent_id ? Number(data.parent_id) : null,
        process_group_id: data.process_group_id ? Number(data.process_group_id) : null,
        business_unit_id: data.business_unit_id ? Number(data.business_unit_id) : null,
        owner_id: data.owner_id ? Number(data.owner_id) : null,
        code: data.code?.trim() || null,
        apqc_code: data.apqc_code?.trim() || null,
        name: data.name?.trim() ?? '',
        description: data.description?.trim() || null,
        criticality: data.criticality || null,
        is_active: Boolean(data.is_active),
    };

    if (includeEntityId && data.entity_id) {
        payload.entity_id = Number(data.entity_id);
    }

    return payload;
}

/**
 * Shared Business Process & Sub-Process Create / Edit form
 * (ADR 0028 Phase 2, process-create-edit.md).
 *
 * Enforces the RCSA v2 2-level invariant inside `business_processes`:
 * - Top-Level Process (`level = 'process'`) clears `parent_id`.
 * - Sub-Process (`level = 'sub_process'`) requires `parent_id` pointing to a
 *   top-level process (`options.parentProcesses`).
 */
export default function ProcessForm({
    mode = 'create',
    process = null,
    options = {},
    form,
    onSubmit,
    cancelHref = null,
    onCancel = null,
    entityChip = null,
}) {
    const { data, setData, errors, processing } = form;
    const isEdit = mode === 'edit';
    const isSubProcess = data.level === 'sub_process';
    const hasChildSubProcesses = isEdit && (process?.sub_processes_count ?? 0) > 0;
    const parentProcesses = options.parentProcesses ?? [];
    const noParentsAvailable = isSubProcess && parentProcesses.length === 0;
    const hasErrors = Object.keys(errors ?? {}).length > 0;

    const handleLevelChange = (nextLevel) => {
        if (nextLevel === 'sub_process' && hasChildSubProcesses) {
            return;
        }
        if (nextLevel === 'process') {
            setData((prev) => ({
                ...prev,
                level: 'process',
                parent_id: '',
            }));
        } else {
            setData((prev) => ({
                ...prev,
                level: 'sub_process',
            }));
        }
    };

    const handleParentChange = (parentIdStr) => {
        const selectedParent = parentProcesses.find((p) => String(p.id) === String(parentIdStr));
        setData((prev) => ({
            ...prev,
            parent_id: parentIdStr,
            process_group_id:
                !prev.process_group_id && selectedParent?.process_group_id
                    ? String(selectedParent.process_group_id)
                    : prev.process_group_id,
            business_unit_id:
                !prev.business_unit_id && selectedParent?.business_unit_id
                    ? String(selectedParent.business_unit_id)
                    : prev.business_unit_id,
        }));
    };

    const submitDisabled =
        processing || noParentsAvailable || (isSubProcess && !data.parent_id);

    return (
        <form onSubmit={onSubmit} className="space-y-6">
            {hasErrors && (
                <div
                    role="alert"
                    className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
                >
                    <p className="font-semibold">Please review the highlighted fields below.</p>
                </div>
            )}

            {entityChip && (
                <div className="rounded-lg border border-blue-200 bg-blue-50 px-4 py-2.5 text-xs text-[#1A365D] flex items-center gap-2">
                    <span className="material-symbols-outlined text-base">link</span>
                    <span>
                        Will be attached to entity: <strong>{entityChip}</strong>
                    </span>
                </div>
            )}

            <div className="bg-white rounded-xl border border-gray-200 p-6 space-y-5">
                {/* 1. Hierarchy Level Toggle */}
                <div>
                    <span className="block text-xs font-semibold text-gray-700 mb-2">
                        Hierarchy Type
                    </span>
                    <div
                        role="radiogroup"
                        aria-label="Process hierarchy level"
                        className="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-1 gap-1"
                    >
                        <button
                            type="button"
                            role="radio"
                            aria-checked={!isSubProcess}
                            onClick={() => handleLevelChange('process')}
                            className={`px-4 py-2 rounded-md text-xs font-semibold transition-colors ${
                                !isSubProcess
                                    ? 'bg-[#1A365D] text-white shadow-xs'
                                    : 'text-gray-700 hover:bg-white'
                            }`}
                        >
                            Top-Level Process
                        </button>
                        <button
                            type="button"
                            role="radio"
                            aria-checked={isSubProcess}
                            disabled={hasChildSubProcesses}
                            onClick={() => handleLevelChange('sub_process')}
                            className={`px-4 py-2 rounded-md text-xs font-semibold transition-colors disabled:opacity-50 disabled:cursor-not-allowed ${
                                isSubProcess
                                    ? 'bg-[#1A365D] text-white shadow-xs'
                                    : 'text-gray-700 hover:bg-white'
                            }`}
                        >
                            Sub-Process
                        </button>
                    </div>
                    {hasChildSubProcesses && (
                        <p className="text-xs text-amber-700 mt-1.5">
                            Cannot convert to a sub-process while child sub-processes exist.
                        </p>
                    )}
                    <InputError message={errors.level} className="mt-1" />
                </div>

                {/* 2. Parent Process (conditional on Sub-Process) */}
                {isSubProcess && (
                    <div aria-live="polite">
                        <label
                            htmlFor="process-parent"
                            className="block text-xs font-semibold text-gray-700 mb-1"
                        >
                            Parent Process <span className="text-red-600">*</span>
                        </label>
                        <select
                            id="process-parent"
                            required
                            aria-required="true"
                            disabled={noParentsAvailable}
                            value={data.parent_id}
                            onChange={(e) => handleParentChange(e.target.value)}
                            className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white disabled:bg-gray-100 disabled:text-gray-400 focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                        >
                            <option value="">Select a top-level parent process…</option>
                            {parentProcesses.map((parent) => (
                                <option key={parent.id} value={parent.id}>
                                    {parent.code} — {parent.name}
                                </option>
                            ))}
                        </select>
                        {noParentsAvailable ? (
                            <p className="text-xs text-amber-700 mt-1">
                                Create a top-level Process first before adding a Sub-Process.
                            </p>
                        ) : (
                            <p className="text-xs text-gray-500 mt-1">
                                Sub-processes inherit their parent&apos;s Process Group and
                                Department unless overridden below.
                            </p>
                        )}
                        <InputError message={errors.parent_id} className="mt-1" />
                    </div>
                )}

                {/* 3. Process Name */}
                <div>
                    <label
                        htmlFor="process-name"
                        className="block text-xs font-semibold text-gray-700 mb-1"
                    >
                        {isSubProcess ? 'Sub-Process Name' : 'Process Name'}{' '}
                        <span className="text-red-600">*</span>
                    </label>
                    <input
                        id="process-name"
                        type="text"
                        required
                        aria-required="true"
                        maxLength={255}
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder={
                            isSubProcess
                                ? 'e.g. SWIFT Wire Reconciliation'
                                : 'e.g. Payment Clearing & Settlement'
                        }
                        className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                    />
                    <InputError message={errors.name} className="mt-1" />
                </div>

                {/* 4. Code & APQC PCF Code */}
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label
                            htmlFor="process-code"
                            className="block text-xs font-semibold text-gray-700 mb-1"
                        >
                            Process Code
                        </label>
                        <input
                            id="process-code"
                            type="text"
                            maxLength={32}
                            value={data.code}
                            onChange={(e) => setData('code', e.target.value)}
                            placeholder={
                                isEdit
                                    ? 'e.g. PRC-0001'
                                    : isSubProcess
                                      ? 'Auto-generated (e.g. SUB-0001)'
                                      : 'Auto-generated (e.g. PRC-0001)'
                            }
                            className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm font-mono focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                        />
                        <InputError message={errors.code} className="mt-1" />
                    </div>

                    <div>
                        <label
                            htmlFor="process-apqc-code"
                            className="block text-xs font-semibold text-gray-700 mb-1"
                        >
                            APQC PCF Code (optional)
                        </label>
                        <input
                            id="process-apqc-code"
                            type="text"
                            maxLength={32}
                            value={data.apqc_code}
                            onChange={(e) => setData('apqc_code', e.target.value)}
                            placeholder="e.g. 9.2.1"
                            className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm font-mono focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                        />
                        <InputError message={errors.apqc_code} className="mt-1" />
                    </div>
                </div>

                {/* 5. Process Group & Department */}
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label
                            htmlFor="process-group"
                            className="block text-xs font-semibold text-gray-700 mb-1"
                        >
                            Process Group (optional)
                        </label>
                        <select
                            id="process-group"
                            value={data.process_group_id}
                            onChange={(e) => setData('process_group_id', e.target.value)}
                            className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                        >
                            <option value="">None (Ungrouped)</option>
                            {(options.processGroups ?? []).map((group) => (
                                <option key={group.id} value={group.id}>
                                    {group.code ? `${group.code} — ${group.name}` : group.name}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.process_group_id} className="mt-1" />
                    </div>

                    <div>
                        <label
                            htmlFor="process-department"
                            className="block text-xs font-semibold text-gray-700 mb-1"
                        >
                            Department / Business Unit (optional)
                        </label>
                        <select
                            id="process-department"
                            value={data.business_unit_id}
                            onChange={(e) => setData('business_unit_id', e.target.value)}
                            className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                        >
                            <option value="">Enterprise / Cross-Departmental</option>
                            {(options.businessUnits ?? []).map((bu) => (
                                <option key={bu.id} value={bu.id}>
                                    {bu.name}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.business_unit_id} className="mt-1" />
                    </div>
                </div>

                {/* 6. Owner & ERM Criticality */}
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label
                            htmlFor="process-owner"
                            className="block text-xs font-semibold text-gray-700 mb-1"
                        >
                            Process Owner (optional)
                        </label>
                        <select
                            id="process-owner"
                            value={data.owner_id}
                            onChange={(e) => setData('owner_id', e.target.value)}
                            className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                        >
                            <option value="">Unassigned</option>
                            {(options.owners ?? []).map((owner) => (
                                <option key={owner.id} value={owner.id}>
                                    {owner.name}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.owner_id} className="mt-1" />
                    </div>

                    <div>
                        <label
                            htmlFor="process-criticality"
                            className="block text-xs font-semibold text-gray-700 mb-1"
                        >
                            ERM Criticality (optional)
                        </label>
                        <select
                            id="process-criticality"
                            value={data.criticality}
                            onChange={(e) => setData('criticality', e.target.value)}
                            className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                        >
                            <option value="">Not specified</option>
                            {(options.criticalities ?? []).map((item) => (
                                <option key={item.value} value={item.value}>
                                    {item.label}
                                </option>
                            ))}
                        </select>
                        <p className="text-[11px] text-gray-500 mt-1">
                            Qualitative ERM process criticality. BCMS critical-service designation is
                            managed separately in Business Continuity.
                        </p>
                        <InputError message={errors.criticality} className="mt-1" />
                    </div>
                </div>

                {/* 7. Description */}
                <div>
                    <label
                        htmlFor="process-description"
                        className="block text-xs font-semibold text-gray-700 mb-1"
                    >
                        Description (optional)
                    </label>
                    <textarea
                        id="process-description"
                        rows={3}
                        maxLength={5000}
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                        placeholder="Describe the scope, inputs, outputs, and key operational activities of this process…"
                        className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-[#1A365D]/20 focus:border-[#1A365D]"
                    />
                    <InputError message={errors.description} className="mt-1" />
                </div>

                {/* 8. Active Status (Edit Mode Only) */}
                {isEdit && (
                    <div className="pt-2 border-t border-gray-100">
                        <label className="inline-flex items-center gap-2.5 text-sm text-gray-700 cursor-pointer">
                            <input
                                type="checkbox"
                                checked={Boolean(data.is_active)}
                                onChange={(e) => setData('is_active', e.target.checked)}
                                className="rounded border-gray-300 text-[#1A365D] focus:ring-[#1A365D]"
                            />
                            <span className="font-medium">Active process</span>
                        </label>
                        <p className="text-xs text-gray-500 mt-1">
                            Inactive processes remain visible for historical audit trail but are
                            excluded from new risk pickers.
                        </p>
                        <InputError message={errors.is_active} className="mt-1" />
                    </div>
                )}
            </div>

            {/* Footer Actions */}
            <div className="flex items-center justify-end gap-3">
                {onCancel ? (
                    <button
                        type="button"
                        disabled={processing}
                        onClick={onCancel}
                        className="btn-secondary text-sm"
                    >
                        Cancel
                    </button>
                ) : (
                    cancelHref && (
                        <Link href={cancelHref} className="btn-secondary text-sm">
                            Cancel
                        </Link>
                    )
                )}
                <button
                    type="submit"
                    disabled={submitDisabled}
                    className="btn-primary text-sm disabled:opacity-50"
                >
                    {processing
                        ? 'Saving…'
                        : isEdit
                          ? 'Save Changes'
                          : isSubProcess
                            ? 'Create Sub-Process'
                            : 'Create Process'}
                </button>
            </div>
        </form>
    );
}
