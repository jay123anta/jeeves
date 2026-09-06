<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2.0.0 renamed "scheme" to "dataset" throughout, including these two columns.
 *
 * The create migrations were updated in place, so a fresh install already
 * arrives with `dataset` and every step here is a no-op -  hence the guards.
 * This exists for installs made against 1.0.0, where the tables carry `scheme`
 * and the 2.0.0 code would query a column that is not there.
 *
 * Renaming rather than dropping keeps the cached queries and, more to the
 * point, the corrections a user took the trouble to submit.
 *
 * The indexes need explicit attention. `ALTER TABLE ... RENAME COLUMN` moves an
 * index onto the new column but leaves its NAME alone, on every engine -  so an
 * upgraded install would keep `..._scheme_index` while a fresh one has
 * `..._dataset_index`. The two populations would then be indistinguishable from
 * code, and the first future migration to write `dropIndex(['dataset', ...])`
 * would resolve the conventional name, succeed on fresh installs and fail on
 * upgraded ones, in production, at migrate time.
 */
return new class extends Migration
{
    /**
     * Table config key => the column it is indexed with, alongside the rename.
     *
     * Read from config rather than hardcoded: the create migrations,
     * TwoTierQueryCache, FeedbackStore and DoctorCommand all resolve these the
     * same way, and an install that renamed its tables would otherwise be
     * skipped in silence and left with a dead cache and unreachable feedback.
     */
    private function targets(): array
    {
        return [
            config('jeeves.cache.table_name', 'jeeves_cache') => 'metric',
            config('jeeves.feedback.table_name', 'jeeves_feedback') => 'feedback_type',
        ];
    }

    public function up(): void
    {
        foreach ($this->targets() as $table => $partner) {
            $this->swap($table, $partner, 'scheme', 'dataset');
        }

        $this->addContractVersion(
            config('jeeves.cache.table_name', 'jeeves_cache')
        );
    }

    /**
     * Rows cached by 1.0.0 leave this null, which is what makes them
     * unreachable: both cache lookups now require the current contract version.
     *
     * They cannot simply be back-filled with the old version number, because
     * the intent JSON inside them still says "scheme" and would be read as a
     * null dataset. Unreachable is the correct outcome -  the question is
     * re-asked once and re-cached under the new shape.
     */
    private function addContractVersion(string $table): void
    {
        if (!Schema::hasTable($table) || Schema::hasColumn($table, 'contract_version')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->unsignedSmallInteger('contract_version')->nullable()->index();
        });
    }

    public function down(): void
    {
        foreach ($this->targets() as $table => $partner) {
            $this->swap($table, $partner, 'dataset', 'scheme');
        }
    }

    private function swap(string $table, string $partner, string $from, string $to): void
    {
        // Guarded on the SOURCE column both ways, so down() is a true inverse:
        // on a fresh install up() does nothing, and down() must also do nothing
        // rather than rename a column up() never touched.
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $from)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($from, $to) {
            $blueprint->renameColumn($from, $to);
        });

        $this->reindex($table, "{$table}_{$from}_index", "{$table}_{$to}_index", [$to]);
        $this->reindex(
            $table,
            "{$table}_{$from}_{$partner}_index",
            "{$table}_{$to}_{$partner}_index",
            [$to, $partner]
        );
    }

    /**
     * Indexes are a performance detail, not a correctness one, and their names
     * vary with how the table was originally created. A failure here must not
     * abort a migration whose column rename has already succeeded.
     */
    private function reindex(string $table, string $old, string $new, array $columns): void
    {
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($old) {
                $blueprint->dropIndex($old);
            });
        } catch (Throwable) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($new, $columns) {
                $blueprint->index($columns, $new);
            });
        } catch (Throwable) {
            // Left without the index rather than without the rename.
        }
    }
};
