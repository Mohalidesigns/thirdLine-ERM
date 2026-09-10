import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AppLayout';
import PageHeader from '@thirdline/ui/Components/PageHeader';
import InputError from '@thirdline/ui/Components/InputError';
import PrimaryButton from '@thirdline/ui/Components/PrimaryButton';
import SecondaryButton from '@thirdline/ui/Components/SecondaryButton';

/**
 * Which sections a board pack contains, and in what order (Phase 5.4).
 *
 * The order is the order of the list, so moving a section moves it in the pack
 * — this is what makes the pack configurable per organisation rather than a
 * fixed template. `sections` posts as an ordered array and
 * BoardPackAssembler::configureSections() stores it as given.
 */
export default function BoardPackSections({ available, selected }) {
    const { data, setData, put, processing, errors } = useForm({
        sections: selected.length > 0 ? selected : Object.keys(available),
    });

    const toggle = (key) => {
        setData(
            'sections',
            data.sections.includes(key) ? data.sections.filter((s) => s !== key) : [...data.sections, key],
        );
    };

    const move = (index, delta) => {
        const next = [...data.sections];
        const target = index + delta;

        if (target < 0 || target >= next.length) return;

        [next[index], next[target]] = [next[target], next[index]];
        setData('sections', next);
    };

    const submit = (event) => {
        event.preventDefault();
        put(route('risk.reports.board-pack.sections.update'));
    };

    const unselected = Object.keys(available).filter((key) => !data.sections.includes(key));

    return (
        <AuthenticatedLayout title="Board Pack Sections">
            <Head title="Board Pack Sections" />

            <PageHeader
                title="Board Pack Sections"
                subtitle="What the pack contains, and the order it is assembled in"
                actions={
                    <Link href={route('risk.reports.board')}>
                        <SecondaryButton type="button">Back to report</SecondaryButton>
                    </Link>
                }
            />

            <form onSubmit={submit} className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <section className="bg-white rounded-xl border border-gray-200 p-6">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-1">In the pack</h3>
                    <p className="text-xs text-gray-500 mb-4">Assembled in this order.</p>

                    {data.sections.length === 0 ? (
                        <p className="text-sm text-gray-400 italic py-6 text-center">
                            A pack needs at least one section.
                        </p>
                    ) : (
                        <ol className="space-y-2">
                            {data.sections.map((key, index) => (
                                <li key={key} className="flex items-center gap-3 p-3 bg-gray-50 rounded-lg">
                                    <span className="text-xs text-gray-400 w-5">{index + 1}</span>
                                    <span className="text-sm text-gray-700 flex-1">{available[key] ?? key}</span>
                                    <button
                                        type="button"
                                        onClick={() => move(index, -1)}
                                        disabled={index === 0}
                                        className="p-1 rounded hover:bg-gray-200 disabled:opacity-30"
                                        aria-label={`Move ${available[key]} up`}
                                    >
                                        <span className="material-symbols-outlined text-lg text-gray-500">arrow_upward</span>
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => move(index, 1)}
                                        disabled={index === data.sections.length - 1}
                                        className="p-1 rounded hover:bg-gray-200 disabled:opacity-30"
                                        aria-label={`Move ${available[key]} down`}
                                    >
                                        <span className="material-symbols-outlined text-lg text-gray-500">arrow_downward</span>
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => toggle(key)}
                                        className="p-1 rounded hover:bg-red-50"
                                        aria-label={`Remove ${available[key]}`}
                                    >
                                        <span className="material-symbols-outlined text-lg text-red-400">close</span>
                                    </button>
                                </li>
                            ))}
                        </ol>
                    )}

                    <InputError message={errors.sections} className="mt-2" />

                    <PrimaryButton disabled={processing || data.sections.length === 0} className="w-full justify-center mt-6">
                        Save section order
                    </PrimaryButton>
                </section>

                <section className="bg-white rounded-xl border border-gray-200 p-6">
                    <h3 className="text-sm font-semibold text-[#1A365D] mb-1">Available</h3>
                    <p className="text-xs text-gray-500 mb-4">Not currently in the pack.</p>

                    {unselected.length === 0 ? (
                        <p className="text-sm text-gray-400 italic py-6 text-center">Every section is in the pack.</p>
                    ) : (
                        <ul className="space-y-2">
                            {unselected.map((key) => (
                                <li key={key} className="flex items-center gap-3 p-3 bg-gray-50 rounded-lg">
                                    <span className="text-sm text-gray-700 flex-1">{available[key]}</span>
                                    <button
                                        type="button"
                                        onClick={() => toggle(key)}
                                        className="text-xs text-[#1A365D] font-medium hover:underline"
                                    >
                                        Add
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </form>
        </AuthenticatedLayout>
    );
}
