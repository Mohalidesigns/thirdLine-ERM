<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 6 — the locally held sanctions lists.
 *
 * WHY A LOCAL COPY AT ALL. The phase requires two free built-in providers, the
 * UNSCR consolidated list and the Nigeria Sanctions List, so that screening
 * works for a client who buys no data. Both are published as files rather than
 * offered as a query API, so "screening" against them means holding them and
 * searching them here. That is also what makes the retrieval requirement
 * satisfiable: Reg. 35 wants the 2021 answer retrievable in 2026, and an
 * answer that depended on a third party's live endpoint is not retrievable at
 * all once they change it.
 *
 * THESE TABLES ARE NOT TENANT-SCOPED. A sanctions list is the same list for
 * every institution in the country; scoping it per tenant would store the
 * United Nations consolidated list once per customer and let one tenant's
 * stale refresh produce a different answer from another's. `organization_id`
 * is deliberately absent, and `TenancyIsolationTest` will not flag these
 * because the column is not there to be missed.
 *
 * `tp_sanctions_entries.normalised_name` IS WHAT THE SEARCH ACTUALLY MATCHES
 * ON. Sanctions lists carry names in transliterated, punctuated and
 * inconsistently ordered forms; searching the printed name would miss
 * "AL-QADI, Yasin" for a subject recorded as "Yasin al-Qadi". The normalised
 * form is folded once at refresh rather than per query, because a per-query
 * fold means a full table scan on every screening run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tp_sanctions_lists', function (Blueprint $table) {
            $table->id();

            // `unscr` | `nigsac` | `ofac_sdn` | `eu_consolidated` | `uk_hmt`.
            $table->string('code', 40)->unique();
            $table->string('name', 200);
            $table->string('publisher', 200)->nullable();
            $table->string('source_url', 500)->nullable();

            // Whether this list ships with the product and needs no
            // subscription. The screening console groups by it, because "you
            // are screening against two lists and could be screening against
            // six" is a useful thing for a client to be told.
            $table->boolean('is_built_in')->default(false);

            $table->timestamp('published_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->string('last_refresh_status', 20)->nullable();
            $table->text('last_refresh_error')->nullable();
            $table->unsignedInteger('entry_count')->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tp_sanctions_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('list_id')->constrained('tp_sanctions_lists')->cascadeOnDelete();

            // The publisher's own identifier, so a refresh updates rather than
            // duplicates and a delisting can be detected.
            $table->string('external_id', 120);

            $table->string('name', 500);

            // Folded at refresh: lower-cased, punctuation stripped, tokens
            // sorted. Indexed, because this is the column every screening run
            // searches.
            $table->string('normalised_name', 500);

            // Every alias the list publishes, each already normalised. A
            // sanctioned party's aliases are the point of the list.
            $table->json('aliases')->nullable();

            $table->string('entity_type', 30)->nullable();
            $table->string('country', 120)->nullable();
            $table->string('date_of_birth', 60)->nullable();
            $table->string('programme', 200)->nullable();
            $table->date('listed_on')->nullable();

            // The publisher's record, verbatim. The evidence Reg. 35 wants
            // retrievable, kept whole rather than as our summary of it.
            $table->json('raw')->nullable();

            $table->timestamps();

            $table->unique(['list_id', 'external_id']);
            $table->index('normalised_name');
            $table->index(['list_id', 'normalised_name'], 'tp_sanctions_list_name_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tp_sanctions_entries');
        Schema::dropIfExists('tp_sanctions_lists');
    }
};
