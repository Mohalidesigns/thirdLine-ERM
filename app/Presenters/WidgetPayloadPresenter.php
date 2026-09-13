<?php

namespace App\Presenters;

use App\Models\WidgetDefinition;
use Illuminate\Support\Facades\Route;

/**
 * Resolves a widget envelope's route-shaped drilldown into URLs the React
 * panel can follow (migration Phase 2).
 *
 * The engine's envelope carries `drilldown` as route NAMES plus params, which
 * the Blade templates resolved with route() at render time. The SPA cannot
 * (route names are a server concern and Ziggy is not guaranteed to list
 * every one), so this presenter does it once, here, and appends:
 *
 *   urls.payload / urls.export  — this widget's own endpoints
 *   urls.drill / urls.create    — the panel menu's "Drill down" and the
 *                                 register widget's "+ New"
 *   data.rows[].url             — register row link (row_route + row_param)
 *   data.cells[].url            — heatmap cell → filtered register
 *
 * A route that does not exist (module not installed, name renamed) yields
 * null rather than an exception: a widget with a dead link still renders.
 */
class WidgetPayloadPresenter
{
    /**
     * @param  array<string, mixed>  $envelope
     * @param  array<string, mixed>  $request  the node/filters/overrides the panel should send back on refresh
     * @return array<string, mixed>
     */
    public function present(array $envelope, WidgetDefinition $definition, array $request = []): array
    {
        $drill = is_array($envelope['drilldown'] ?? null) ? $envelope['drilldown'] : [];
        $params = (array) ($drill['params'] ?? []);

        $envelope['urls'] = [
            'payload' => route('risk.widgets.payload', $definition),
            'export' => route('risk.widgets.export', $definition),
            'drill' => $this->url($drill['route'] ?? null, $params),
            'create' => $this->url($drill['create_route'] ?? null, $params),
        ];
        $envelope['request'] = $request;

        $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];

        if (isset($data['rows']) && is_array($data['rows']) && ! empty($drill['row_route'])) {
            $param = $drill['row_param'] ?? 'id';

            $data['rows'] = array_map(function ($row) use ($drill, $params, $param) {
                if (is_array($row) && isset($row['id'])) {
                    $row['url'] = $this->url($drill['row_route'], array_merge($params, [$param => $row['id']]));
                }

                return $row;
            }, $data['rows']);
        }

        if (isset($data['cells']) && is_array($data['cells']) && ! empty($drill['route'])) {
            $data['cells'] = array_map(function ($cell) use ($drill, $params) {
                if (is_array($cell)) {
                    $filters = (array) ($cell['filters'] ?? []);
                    $cell['url'] = $this->url($drill['route'], $filters === [] ? $params : array_merge($params, ['filters' => $filters]));
                }

                return $cell;
            }, $data['cells']);
        }

        $envelope['data'] = $data;

        return $envelope;
    }

    private function url(?string $name, array $params): ?string
    {
        if ($name === null || $name === '' || ! Route::has($name)) {
            return null;
        }

        try {
            return route($name, $params);
        } catch (\Throwable) {
            return null;
        }
    }
}
