<?php

namespace App\Livewire\Widgets;

use App\Models\GraphObject;
use App\Models\WidgetDefinition;
use App\Services\Widgets\WidgetContext;
use App\Services\Widgets\WidgetDataService;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * WP-08 TASK 2/3 — one dashboard panel: a widget definition rendered in the
 * page's context, carrying the Corporater ⋮ menu (refresh, export, drill
 * down; configure and remove belong to the builder, which renders its own
 * chrome around this same component).
 *
 * The panel re-resolves its payload on every Livewire render, so "refresh"
 * is just a render and the period selector (a full page reload, by design)
 * needs no wiring here. Register mechanics (search, paging) are runtime
 * filters merged into the widget context — the engine whitelists them like
 * everything else.
 */
class WidgetPanel extends Component
{
    public int $widgetId;

    public ?int $nodeId = null;

    /** @var array<string, mixed> per-placement overrides from the dashboard layout */
    public array $overrides = [];

    /** @var array<string, mixed> live filter state (register search/page, drill narrowing) */
    public array $filters = [];

    public function mount(int $widgetId, ?int $nodeId = null, array $overrides = []): void
    {
        $this->widgetId = $widgetId;
        $this->nodeId = $nodeId;
        $this->overrides = $overrides;
    }

    public function refresh(): void
    {
        // A render IS a refresh; the action exists so the ⋮ menu can call it.
        $this->dispatch('widget-rerendered');
    }

    public function search(string $term): void
    {
        $this->filters['_search'] = $term;
        $this->filters['_page'] = 1;
    }

    public function goToPage(int $page): void
    {
        $this->filters['_page'] = max(1, $page);
    }

    #[On('period-changed')]
    public function onPeriodChanged(): void
    {
        // PeriodContext is request-scoped; the next render reads the new one.
    }

    public function export()
    {
        $payload = $this->payload();

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

    public function render()
    {
        return view('livewire.widgets.widget-panel', [
            'payload' => $this->payload(),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $definition = WidgetDefinition::query()->find($this->widgetId);

        if ($definition === null) {
            return [
                'state' => 'error',
                'widget_id' => $this->widgetId,
                'code' => 'missing',
                'type' => 'missing',
                'title' => 'Missing widget',
                'data' => [],
                'visualisation' => [],
                'meta' => [],
            ];
        }

        $node = $this->nodeId === null ? null : GraphObject::query()->find($this->nodeId);

        $context = WidgetContext::for(auth()->user(), $node, $this->filters);

        return app(WidgetDataService::class)->render($definition, $context, $this->overrides);
    }

    /**
     * A generic tabular flattening of a payload for CSV export: tables export
     * their rows; keyed series export label/value pairs; anything else exports
     * its scalar leaves. Honest and unclever — the Report Studio (WP-12) owns
     * formatted exports.
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
