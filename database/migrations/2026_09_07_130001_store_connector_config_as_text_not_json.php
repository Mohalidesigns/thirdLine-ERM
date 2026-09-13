<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `connectors.config` holds ciphertext, so it cannot be a JSON column.
 *
 * `Connector` casts both `config` and `credentials` as `encrypted:array` — the
 * class comment is explicit that both are encrypted at rest and only `config`
 * is ever rendered. `credentials` was declared `text` and works. `config` was
 * declared `json`, and Laravel's encrypter produces a base64 envelope, which is
 * not valid JSON.
 *
 * SO EVERY INSERT INTO `connectors` HAS ALWAYS FAILED ON A REAL DATABASE.
 * MariaDB rejects it through the `json_valid()` CHECK constraint it puts behind
 * a json column; MySQL 8 rejects it through the native JSON type. The feature
 * appeared to work only because the suite ran on SQLite, where a json column is
 * untyped TEXT and validates nothing — creating a connector has never once
 * succeeded anywhere this product is actually deployed. The original
 * migration's own comment shows how it happened: "Encrypted, and apart from
 * config", written by somebody who believed only `credentials` was encrypted.
 *
 * There is deliberately NO DATA MIGRATION: a column that has never accepted a
 * write has nothing in it to convert.
 *
 * `text`, matching `credentials`, rather than `longText`. An encrypted
 * connector config is a handful of settings — a disk, a path, a few flags — and
 * 64KB of ciphertext is far beyond anything the connector screen can produce.
 *
 * THE ORIGINAL MIGRATION IS LEFT SAYING `json` ON PURPOSE. Editing an applied
 * migration would make the repository claim a schema that deployed databases do
 * not have, and it would stop this migration from ever running in a test — a
 * fresh database would arrive already correct, and the fix would go unexercised
 * by the suite that is supposed to prove it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No explicit constraint drop. MariaDB attaches `json_valid()` to a
        // json column as an INLINE check, and rewriting the column definition
        // takes the check with it — verified against MariaDB 10.4 before this
        // was written. An `ALTER TABLE ... DROP CONSTRAINT` is not merely
        // unnecessary, it FAILS: the inline check is not droppable by name.
        Schema::table('connectors', function (Blueprint $table) {
            $table->text('config')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('connectors', function (Blueprint $table) {
            $table->json('config')->nullable()->change();
        });
    }
};
