<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('jeeves.feedback.table_name', 'jeeves_feedback');

        Schema::create($tableName, function (Blueprint $table) {
            $table->id();
            $table->text('query');                                // Original natural language query
            $table->string('dataset', 100)->nullable()->index();   // Which dataset was queried
            $table->text('generated_sql')->nullable();            // The SQL that was generated
            $table->text('correction')->nullable();               // User's correction text
            $table->text('corrected_sql')->nullable();            // Optional: correct SQL
            $table->string('feedback_type', 50)->default('other') // wrong_metric, wrong_table, wrong_result, wrong_filter, positive, other
                ->index();
            $table->integer('times_applied')->default(0);         // How many times this correction was fed into prompts
            $table->timestamps();

            $table->index(['dataset', 'feedback_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('jeeves.feedback.table_name', 'jeeves_feedback'));
    }
};
