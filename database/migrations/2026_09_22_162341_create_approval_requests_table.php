<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Requests that need someone's word before they count: leave, a purchase,
     * an expense.
     *
     * In most Iranian companies this happens in a group chat and then nobody
     * can find it in March. Here it has a requester, an approver, a timestamp
     * and a reason — and approved leave feeds straight back into the follow-up
     * engine, which stops chasing someone the company itself sent home.
     */
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();

            // Who it was routed to, from the requester's manager on the pivot.
            // Null means nobody is named as their manager yet, and then any
            // owner or admin may decide it — a request must never be stuck
            // waiting for a person who does not exist.
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('type', 20);
            $table->string('status', 20)->default('pending');

            $table->string('title');
            $table->text('reason')->nullable();

            // Leave only. Stored as dates rather than timestamps: a day off is
            // a day, and a timezone-shifted timestamp would move it.
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            // Purchases and expenses, in rial. Integer because rial has no
            // subunit in practice and float money is a bug waiting to happen.
            $table->unsignedBigInteger('amount')->nullable();

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The inbox query: everything still pending in this workspace.
            $table->index(['workspace_id', 'status']);
            $table->index(['approver_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
