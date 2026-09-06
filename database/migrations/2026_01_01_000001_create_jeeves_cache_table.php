<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('jeeves.cache.table_name', 'jeeves_cache');

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->string('query_hash', 64)->unique()->index();
            $table->text('original_query');
            $table->text('normalized_query')->index();
            // Which parsed-intent contract this row was written against. Rows
            // from an older contract are unreachable rather than misread.
            $table->unsignedSmallInteger('contract_version')->nullable()->index();
            $table->string('dataset', 100)->nullable()->index();
            $table->string('metric', 100)->nullable();
            $table->string('group_value', 255)->nullable();
            $table->json('intent')->nullable();
            $table->integer('limit_value')->nullable();
            $table->string('order_direction', 10)->nullable();
            $table->string('query_type', 50)->nullable();
            $table->unsignedBigInteger('hit_count')->default(1);
            $table->timestamp('last_hit_at')->nullable()->index();
            $table->timestamps();

            $table->index(['dataset', 'metric']);
        });
    }

    public function down(): void
    {
        $tableName = config('jeeves.cache.table_name', 'jeeves_cache');
        Schema::dropIfExists($tableName);
    }
};
