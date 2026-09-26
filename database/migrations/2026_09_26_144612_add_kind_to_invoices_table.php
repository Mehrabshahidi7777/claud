<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What an invoice pays for. A `term` buys a month or a year; `seats`
     * adds people to the term already running, priced for the days left, and
     * leaves the end date where it is. Existing invoices are all terms.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('kind', 10)->default('term')->after('plan_key');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
