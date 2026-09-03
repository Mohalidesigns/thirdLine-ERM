<?php

namespace App\Http\Controllers\Risk;

use App\Http\Controllers\Controller;
use App\Models\GraphObject;
use App\Models\WidgetDefinition;
use App\Presenters\WidgetPayloadPresenter;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetDataService;
use Illuminate\Http\Request;

/**
 * One widget's payload, on demand (migration Phase 2 — what the Livewire
 * WidgetPanel component re-resolved on every render).
 *
 * The page renders every panel's payload server-side on first load; this
 * endpoint serves refresh, the register widgets' search and paging (runtime
 * filters merged into the widget context, whitelisted by the engine like
 * everything else), and the CSV export.
 */
class WidgetController extends Controller
{
    public function __construct(
        private readonly WidgetDataService $widgets,
        private readonly WidgetPayloadPresenter $payloads,
    ) {}

    public function payload(Request $request, WidgetDefinition $widget)
    {
        return response()->json($this->payloads->present($this->render($request, $widget), $widget, [
            'node' => $request->integer('node') ?: null,
            'filters' => (object) $request->input('filters', []),
            'overrides' => (object) $request->input('overrides', []),
        ]));
    }

    public function export(Request $request, WidgetDefinition $widget)
    {
        $payload = $this->render($request, $widget);
        $rows = $this->flatten($payload['data'] ?? []);
        $filename = ($payload['code'] ?? 'widget').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array<string, mixed>
     */
    private function render(Request $request, WidgetDefinition $widget): array
    {
        $validated = $request->validate([
            'node' => ['nullable', 'integer'],
            'filters' => ['nullable', 'array'],
            'overrides' => ['nullable', 'array'],
        ]);

        // The tenant global scope makes a foreign node id resolve to nothing,
        // which the engine renders as "no node" rather than another tenant's.
        $node = empty($validated['node']) ? null : GraphObject::query()->find((int) $validated['node']);

        $context = WidgetContext::for($request->user(), $node, (array) ($validated['filters'] ?? []));

        return $this->widgets->render($widget, $context, (array) ($validated['overrides'] ?? []));
    }

    /**
     * A generic tabular flattening for CSV: tables export their rows; keyed
     * series export label/value pairs; anything else exports its scalar
     * leaves. Honest and unclever — the Report Studio owns formatted exports.
     *
     * @return list<array<int, mixed>>
     */
    private function flatten(array $data): array
    {
        foreach (['rows', 'bars', 'slices', 'tiles', 'items', 'points', 'cells', 'units', 'groups'] as $key) {
            if (isset($data[$key]) && is_array($data[$key]) && $data[$key] !== []) {
                $records = array_values(array_filter($data[$key], 'is_array'));

                if ($records === []) {
                    break;
                }

                $headers = array_keys($records[0]);

                return array_merge(
                    [$headers],
                    array_map(fn (array $r) => array_map(
                        fn ($v) => is_array($v) ? json_encode($v) : $v,
                        array_values(array_merge(array_fill_keys($headers, null), $r)),
                    ), $records),
                );
            }
        }

        return [['key', 'value'], ...collect($data)
            ->filter(fn ($v) => is_scalar($v) || $v === null)
            ->map(fn ($v, $k) => [$k, $v])
            ->values()
            ->all()];
    }
}
