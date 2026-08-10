<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConfigBundle;
use App\Models\ConfigBundleApplication;
use App\Services\Configuration\ConfigurationExporter;
use App\Services\Configuration\ConfigurationImporter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
        return view('admin.configuration.index', [
            'bundles' => ConfigBundle::exported()->latest('exported_at')->limit(50)->get(),
            'applications' => ConfigBundleApplication::with(['bundle', 'actor'])
                ->latest('applied_at')->limit(50)->get(),
            'diff' => session('configuration-diff'),
            'diffBundleId' => session('configuration-diff-bundle'),
        ]);
    }

    public function export(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:100|regex:/^[a-z0-9][a-z0-9_\-]*$/',
            'name' => 'required|string|max:200',
            'description' => 'nullable|string|max:2000',
        ]);

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
        $this->assertOwned($bundle);

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
    public function diff(Request $request)
    {
        $validated = $request->validate([
            'bundle_id' => 'nullable|integer|exists:config_bundles,id',
            'file' => 'nullable|file|mimetypes:application/json,text/plain|max:10240',
        ]);

        $payload = $this->payloadFrom($validated, $request);

        if ($payload === null) {
            return back()->withErrors(['bundle' => 'Choose a stored bundle or upload one exported by config:export.']);
        }

        $result = $this->importer->plan($payload);

        return back()
            ->with('configuration-diff', $result['diff'])
            ->with('configuration-diff-bundle', $validated['bundle_id'] ?? null);
    }

    public function apply(Request $request, ConfigBundle $bundle)
    {
        $this->assertOwned($bundle);

        $options = $request->validate([
            'force' => 'sometimes|boolean',
            'prune' => 'sometimes|boolean',
            'confirm' => 'accepted',
        ]);

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
        $this->assertOwned($application);

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
            $this->assertOwned($bundle);

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

    /**
     * The tenancy scope already filters reads, but route-model binding on an
     * explicit id is the one place it is worth checking twice: a bundle is a
     * complete statement of another organisation's configuration.
     */
    private function assertOwned(ConfigBundle|ConfigBundleApplication $model): void
    {
        abort_unless($model->organization_id === TenantContext::organizationId(), 403);
    }
}
