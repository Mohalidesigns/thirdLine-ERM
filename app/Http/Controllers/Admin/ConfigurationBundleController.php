<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Configuration\ApplyBundleRequest;
use App\Http\Requests\Admin\Configuration\DiffBundleRequest;
use App\Http\Requests\Admin\Configuration\ExportBundleRequest;
use App\Models\ConfigBundle;
use App\Models\ConfigBundleApplication;
use App\Services\Configuration\ConfigurationExporter;
use App\Services\Configuration\ConfigurationImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * WP-05 TASK 4 — the browser half of the bundle workflow.
 *
 * Everything here delegates to the same three services the artisan commands
 * use. A second implementation of "apply a bundle" that happened to live in a
 * controller would be a second thing that can be wrong about what applying
 * means, and this is the operation where being wrong is expensive.
 */
class ConfigurationBundleController extends Controller
{
    public function __construct(
        private ConfigurationExporter $exporter,
        private ConfigurationImporter $importer,
    ) {}

    public function index()
    {
        Gate::authorize('viewAny', ConfigBundle::class);

        return Inertia::render('Admin/ConfigBundles/Index', [
            'bundles' => ConfigBundle::exported()
                ->latest('exported_at')
                ->limit(50)
                ->get()
                ->map(fn (ConfigBundle $bundle) => array_merge($bundle->only([
                    'id', 'code', 'name', 'description', 'version', 'checksum', 'source_environment',
                ]), [
                    'label' => $bundle->label(),
                    'exported_at' => $bundle->exported_at?->toIso8601String(),
                    'total_rows' => $bundle->totalRows(),
                    'section_counts' => $bundle->sectionCounts(),
                    'download_url' => route('admin.configuration.download', $bundle),
                ]))->values()->all(),
            'applications' => ConfigBundleApplication::with(['bundle:id,code,name,version', 'actor:id,name'])
                ->latest('applied_at')
                ->limit(50)
                ->get()
                ->map(fn (ConfigBundleApplication $application) => array_merge($application->only([
                    'id', 'mode', 'outcome',
                ]), [
                    'applied_at' => $application->applied_at?->toIso8601String(),
                    'summary' => $application->summary(),
                    'bundle' => $application->getRelationValue('bundle')?->only(['id', 'code', 'name', 'version']),
                    'actor' => $application->getRelationValue('actor')?->name,
                    'can_rollback' => $application->mode !== 'dry_run'
                        && $application->outcome !== 'no_changes'
                        && Gate::allows('rollback', $application),
                ]))->values()->all(),
        ]);
    }

    public function export(ExportBundleRequest $request)
    {
        $validated = $request->validated();

        $bundle = $this->exporter->export(
            code: $validated['code'],
            name: $validated['name'],
            description: $validated['description'] ?? null,
        );

        return back()->with('success', "Exported {$bundle->label()} — {$bundle->totalRows()} row(s) "
            .'across '.count($bundle->sectionCounts()).' section(s).');
    }

    /**
     * The bundle as a file, so it can be committed to a repository or carried
     * to another environment.
     */
    public function download(ConfigBundle $bundle)
    {
        Gate::authorize('view', $bundle);

        $filename = "{$bundle->code}-v{$bundle->version}.json";

        return response()->streamDownload(function () use ($bundle) {
            echo json_encode([
                'code' => $bundle->code,
                'name' => $bundle->name,
                'version' => $bundle->version,
                'checksum' => $bundle->checksum,
                'exported_at' => $bundle->exported_at?->toIso8601String(),
                'source_environment' => $bundle->source_environment,
                'payload' => $bundle->payload,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }, $filename, ['Content-Type' => 'application/json']);
    }

    /**
     * The dry run. Always available, never writes configuration.
     */
    public function diff(DiffBundleRequest $request)
    {
        $validated = $request->validated();
        $payload = $this->payloadFrom($validated, $request);

        if ($payload === null) {
            return back()->withErrors(['bundle' => 'Choose a stored bundle or upload one exported by config:export.']);
        }

        $result = $this->importer->plan($payload);
        $bundle = ! empty($validated['bundle_id']) ? ConfigBundle::find($validated['bundle_id']) : null;

        // Rendered rather than flashed. The Blade screen put the whole diff
        // through the session and read it back on the next request, which is a
        // configuration-sized document in the session store for the sake of one
        // redirect. It is the response now.
        return Inertia::render('Admin/ConfigBundles/Diff', [
            'diff' => $result['diff'],
            'bundle' => $bundle?->only(['id', 'code', 'name', 'version']),
            'uploaded' => $bundle === null,
        ]);
    }

    public function apply(ApplyBundleRequest $request, ConfigBundle $bundle)
    {
        $options = $request->validated();

        try {
            $result = $this->importer->apply(
                $bundle,
                force: (bool) ($options['force'] ?? false),
                prune: (bool) ($options['prune'] ?? false),
            );
        } catch (ValidationException $error) {
            return back()->withErrors($error->errors());
        }

        $application = $result['application'];

        if ($application->outcome === 'no_changes') {
            return back()->with('success', 'Nothing to apply — this configuration is already in place.');
        }

        return back()->with('success', "Applied {$bundle->label()}: {$application->summary()}. "
            .'Roll back from the history below if this was not what you intended.');
    }

    public function rollback(ConfigBundleApplication $application)
    {
        Gate::authorize('rollback', $application);

        try {
            $rollback = $this->importer->rollback($application);
        } catch (ValidationException $error) {
            return back()->withErrors($error->errors());
        }

        return back()->with('success', 'Rolled back to the configuration as it was before '
            ."log entry #{$application->id}. The restore is recorded as #{$rollback->id}.");
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>|null
     */
    private function payloadFrom(array $validated, Request $request): ?array
    {
        if (! empty($validated['bundle_id'])) {
            $bundle = ConfigBundle::findOrFail($validated['bundle_id']);
            Gate::authorize('view', $bundle);

            return $bundle->payload;
        }

        if (! $request->hasFile('file')) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($request->file('file')->getRealPath()), true);

        return is_array($decoded) && isset($decoded['payload']) && is_array($decoded['payload'])
            ? $decoded['payload']
            : null;
    }
}
