import { Head, Link } from "@inertiajs/react";
import AppLayout from "@/Layouts/AppLayout";
import PageHeader from "@thirdline/ui/Components/PageHeader";

/**
 * Shown, with HTTP 403, when someone opens the review page of an assessment
 * they filed themselves (RCSA-03).
 *
 * The refusal is right and stays: whoever files an assessment cannot review
 * it — a two-person control. What was wrong was the bare "403 THIS ACTION IS
 * UNAUTHORIZED" that gave no reason and no way back. This page says why, and
 * links to the two places the person can usefully go.
 *
 * Every prop is optional. The page must still render, and say the same thing,
 * if the server sends none of them — a failure page that itself fails to
 * render is the worst place for a crash.
 */
export default function SegregationOfDuties({
    assessment = null,
    urls = null,
}) {
    const queueUrl = urls?.queue ?? route("rcsa.review.index");
    const assessmentUrl =
        urls?.assessment ??
        (assessment?.id
            ? route("rcsa.assessments.show", assessment.id)
            : null);

    const facts = [
        ["Business unit", assessment?.business_unit],
        ["Cycle", assessment?.cycle],
        ["Filed", assessment?.submitted_at],
    ].filter(([, value]) => value);

    return (
        <AppLayout
            header={
                <PageHeader
                    title="You cannot review this assessment"
                    subtitle="Two-person control"
                    breadcrumbs={[
                        { label: "RCSA" },
                        { label: "ORM review", href: queueUrl },
                    ]}
                />
            }
        >
            <Head title="You cannot review this assessment" />

            <div
                role="alert"
                className="max-w-2xl rounded-lg border border-amber-200 bg-amber-50 p-6 text-amber-900"
            >
                <h3 className="text-base font-semibold">
                    You filed this assessment, so someone else has to review it
                </h3>
                <p className="mt-2 text-sm">
                    Whoever submits an assessment cannot also review it. The
                    check exists so that no result is approved by the person who
                    produced it. Nothing is wrong with your filing — it is
                    waiting for a different reviewer to pick it up.
                </p>

                {facts.length > 0 && (
                    <dl className="mt-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                        {facts.map(([term, value]) => (
                            <div key={term} className="contents">
                                <dt className="font-medium">{term}</dt>
                                <dd>{value}</dd>
                            </div>
                        ))}
                    </dl>
                )}

                <div className="mt-5 flex flex-wrap gap-2">
                    <Link href={queueUrl} className="btn-primary text-sm">
                        Back to the review queue
                    </Link>
                    {assessmentUrl && (
                        <Link
                            href={assessmentUrl}
                            className="btn-secondary text-sm"
                        >
                            Open the assessment
                        </Link>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
