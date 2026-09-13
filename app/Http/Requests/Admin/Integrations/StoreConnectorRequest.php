<?php

namespace App\Http\Requests\Admin\Integrations;

use App\Models\Connector;
use App\Support\Http\OutboundUrlGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Configure a connector (migration Phase 6.7).
 *
 * The URL check is the addition. RestConnector and CsvConnector both call
 * `OutboundUrlGuard::assertSafe()` before they fetch anything, so a connector
 * pointed at a link-local address or an internal host has never been able to
 * reach it — the hole was never open. What was missing is the courtesy the
 * webhook screen has always had: **the check at save time**, so the person who
 * typed the URL finds out now rather than from a failed run later, with the
 * failure recorded against the connector as though the source were down.
 *
 * The guard still runs at fetch time, and must: a URL that was safe when it was
 * typed can resolve somewhere else afterwards.
 *
 * `config`, `credentials` and `field_map` stay free-form arrays. Their shape is
 * the DRIVER's — `describe()` is what the form is built from, so a new
 * connector type gets a UI without anybody writing one — and enumerating the
 * keys here would mean this class had to be edited every time a driver gained a
 * setting. The two things that are not the driver's business, the type and the
 * schedule, are checked against the registered lists.
 */
class StoreConnectorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Connector::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(array_keys((array) config('connectors.drivers')))],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'config' => ['nullable', 'array'],
            'credentials' => ['nullable', 'array'],
            'field_map' => ['nullable', 'array'],
            'schedule' => ['nullable', 'string', Rule::in(array_keys((array) config('connectors.schedules')))],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator) {
            $url = trim((string) $this->input('config.url', ''));

            if ($url === '') {
                return;
            }

            try {
                OutboundUrlGuard::assertSafe($url);
            } catch (RuntimeException $e) {
                $validator->errors()->add('config.url', $e->getMessage());
            }
        });
    }
}
