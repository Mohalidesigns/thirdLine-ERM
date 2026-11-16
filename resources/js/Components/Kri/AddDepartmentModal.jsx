import { useForm } from '@inertiajs/react';
import Modal from '@thirdline/ui/Components/Modal';

export default function AddDepartmentModal({ isOpen, onClose, existingDepartments = [] }) {
    const form = useForm({
        name: '',
        code: '',
        parent_id: '',
        description: '',
    });

    const submit = (e) => {
        e.preventDefault();
        form.post(route('risk.kri.departments.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Modal show={isOpen} onClose={onClose} maxWidth="lg">
            <form onSubmit={submit} className="p-6">
                <div className="flex items-center justify-between pb-3 border-b border-gray-200 mb-4">
                    <div className="flex items-center gap-2">
                        <span className="material-symbols-outlined text-[#1A365D]">corporate_fare</span>
                        <h3 className="text-lg font-bold text-gray-900">Add New Department</h3>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="text-gray-400 hover:text-gray-600 text-lg"
                    >
                        ✕
                    </button>
                </div>

                <p className="text-xs text-gray-500 mb-4">
                    Create a new department to be added to the KRI Library, departmental KRI monitoring, and contacts.
                </p>

                <div className="space-y-4">
                    <div>
                        <label className="block text-xs font-semibold text-gray-700 mb-1">
                            Department Name <span className="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            required
                            placeholder="e.g. Information Security, Treasury, Legal"
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            className="form-input w-full text-sm rounded-md border-gray-300"
                        />
                        {form.errors.name && (
                            <p className="text-xs text-red-600 mt-1">{form.errors.name}</p>
                        )}
                    </div>

                    <div>
                        <label className="block text-xs font-semibold text-gray-700 mb-1">
                            Department Code
                        </label>
                        <input
                            type="text"
                            placeholder="e.g. INF-SEC, TSY, LEG (leave blank to auto-generate)"
                            value={form.data.code}
                            onChange={(e) => form.setData('code', e.target.value.toUpperCase())}
                            className="form-input w-full text-sm font-mono uppercase rounded-md border-gray-300"
                        />
                        {form.errors.code && (
                            <p className="text-xs text-red-600 mt-1">{form.errors.code}</p>
                        )}
                    </div>

                    <div>
                        <label className="block text-xs font-semibold text-gray-700 mb-1">
                            Parent Department / Group (Optional)
                        </label>
                        <select
                            value={form.data.parent_id}
                            onChange={(e) => form.setData('parent_id', e.target.value)}
                            className="form-select w-full text-sm rounded-md border-gray-300"
                        >
                            <option value="">None (Top-level department)</option>
                            {existingDepartments.map((d) => (
                                <option key={d.id} value={d.id}>
                                    {d.name} {d.code ? `(${d.code})` : ''}
                                </option>
                            ))}
                        </select>
                        {form.errors.parent_id && (
                            <p className="text-xs text-red-600 mt-1">{form.errors.parent_id}</p>
                        )}
                    </div>

                    <div>
                        <label className="block text-xs font-semibold text-gray-700 mb-1">
                            Description (Optional)
                        </label>
                        <textarea
                            rows={2}
                            placeholder="Brief summary of the department's mandate..."
                            value={form.data.description}
                            onChange={(e) => form.setData('description', e.target.value)}
                            className="form-textarea w-full text-sm rounded-md border-gray-300"
                        />
                    </div>
                </div>

                <div className="mt-6 pt-4 border-t border-gray-100 flex items-center justify-end gap-3">
                    <button
                        type="button"
                        onClick={onClose}
                        className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="btn-primary inline-flex items-center gap-1.5 text-sm disabled:opacity-50"
                    >
                        <span className="material-symbols-outlined text-base">add</span>
                        {form.processing ? 'Creating...' : 'Create Department'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
