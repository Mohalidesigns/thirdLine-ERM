<?php

namespace App\Integrations\Contracts;

use App\Models\Connector as ConnectorModel;
use Carbon\CarbonInterface;

/**
 * WP-07 TASK 4 — what a connector has to be able to do.
 *
 * Kept to six methods on purpose. A connector framework that demands a great
 * deal from each implementation ends up with one implementation, and the whole
 * point is that a customer's own integration team can add the third one without
 * touching the engine.
 *
 * pull() RETURNS ROWS; IT DOES NOT WRITE THEM. Writing is the framework's job,
 * so that tenancy, the dry run, the reconciliation report and the run log
 * behave identically no matter who wrote the connector. A connector that could
 * write directly is a connector that can get tenancy wrong.
 */
interface Connector
{
    /**
     * What this connector is and what it needs configuring.
     *
     * Drives the admin form, so a new connector type gets a UI without anybody
     * writing one.
     *
     * @return array{
     *     type: string,
     *     label: string,
     *     description: string,
     *     config: array<string, array{label:string, type:string, required?:bool, help?:string}>,
     *     credentials: array<string, array{label:string, type:string, required?:bool}>,
     * }
     */
    public function describe(): array;

    /**
     * Prepare any authentication the source needs.
     */
    public function authenticate(ConnectorModel $connector): void;

    /**
     * Can we reach the source with the configuration as it stands?
     *
     * Must not change anything. It is the button somebody presses while they
     * are still typing the settings in.
     *
     * @return array{ok: bool, message: string, detail?: array<string, mixed>}
     */
    public function testConnection(ConnectorModel $connector): array;

    /**
     * Read rows from the source.
     *
     * $since lets an incremental connector ask only for what has changed. A
     * connector that cannot do incremental reads may ignore it and return
     * everything — the framework deduplicates on write.
     *
     * @return iterable<int, array<string, mixed>>
     */
    public function pull(ConnectorModel $connector, ?CarbonInterface $since = null): iterable;

    /**
     * Send something back to the source, for connectors that are two-way.
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, message: string}
     */
    public function push(ConnectorModel $connector, array $payload): array;

    /**
     * The fields this connector produces, so the admin screen can offer a
     * mapping rather than asking somebody to type column names from memory.
     *
     * @return array<string, string> field => human label
     */
    public function fieldMap(ConnectorModel $connector): array;
}
