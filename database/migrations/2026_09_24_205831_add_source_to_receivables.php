<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a receivable came from, so importing the same export twice
     * updates the rows instead of doubling them.
     *
     * The unique index is the thing doing the work. An importer that checks
     * in PHP first and inserts second is a race away from duplicates the
     * moment two people upload the same file, and a company seeing every
     * invoice twice never trusts the figures again.
     */
    public function up(): void
    {
        Schema::table('receivables', function (Blueprint $table) {
            // Null for anything typed in by hand, which is most of them.
            $table->string('source', 30)->nullable()->after('status');
            $table->string('external_ref', 100)->nullable()->after('source');

            $table->unique(['workspace_id', 'source', 'external_ref'], 'receivables_source_ref_unique');
        });
    }

    public function down(): void
    {
        Schema::table('receivables', function (Blueprint $table) {
            $table->dropUnique('receivables_source_ref_unique');
            $table->dropColumn(['source', 'external_ref']);
        });
    }
};
