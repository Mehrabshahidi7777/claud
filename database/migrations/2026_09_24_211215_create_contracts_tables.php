<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Things that expire once, rather than coming back on a cycle.
     *
     * Kept apart from recurring work deliberately. A recurrence repeats on a
     * fixed cadence and "doing it" means doing the same thing again. A
     * contract has a counterparty and a term, and renewing it means agreeing
     * a *new* term whose length nobody knows in advance — a staff contract
     * renewed for six months this time and a year the next. Forcing both into
     * one table would mean a cadence column that lies.
     *
     * Why it earns a module of its own: an expired staff contract and a
     * lapsed contractor qualification are not inconveniences. One is a
     * liability the company does not know it has, the other disqualifies it
     * from tenders it has already spent money bidding for.
     */
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            // Who inside the company has to get it renewed.
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('kind', 20);
            $table->string('party_type', 20);
            $table->string('party_name');

            // Set when the counterparty is one of our own members, which is
            // what makes a staff contract show up on that person's file
            // rather than as a name typed twice.
            $table->foreignId('party_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->string('reference', 100)->nullable();
            $table->string('note', 1000)->nullable();

            // The term currently in force. Past terms live in contract_terms.
            $table->date('starts_on');
            $table->date('expires_on');
            $table->unsignedBigInteger('value')->nullable();

            $table->unsignedSmallInteger('notice_days')->default(30);

            // Some agreements roll over unless cancelled. The reminder then
            // is not "renew this" but "decide before it renews itself", which
            // is a different sentence and a different deadline.
            $table->boolean('auto_renews')->default(false);

            $table->string('status', 20)->default('active');
            $table->unsignedInteger('renewals')->default(0);
            $table->date('ended_on')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'status', 'expires_on']);
        });

        /*
        | Every term this contract has run, so "چند بار تمدید شده و با چه
        | مبلغی" is a question the file answers rather than somebody's memory.
        */
        Schema::create('contract_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->date('starts_on');
            $table->date('expires_on');
            $table->unsignedBigInteger('value')->nullable();
            $table->string('note', 500)->nullable();

            $table->timestamps();

            $table->index(['contract_id', 'starts_on']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('contract_id')->nullable()->after('recurring_task_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contract_id');
        });

        Schema::dropIfExists('contract_terms');
        Schema::dropIfExists('contracts');
    }
};
