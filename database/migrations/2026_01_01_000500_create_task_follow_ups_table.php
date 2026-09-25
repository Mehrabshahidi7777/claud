<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ladder itself. One row per rung per task, written when the task gets
     * a deadline and rewritten whenever that deadline moves.
     */
    public function up(): void
    {
        Schema::create('task_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('step');
            $table->string('channel');

            // Who this rung addresses — the assignee for a chase, their
            // manager for an escalation. Resolved when the rung is built so a
            // later reporting-line change cannot silently redirect history.
            $table->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('scheduled_at');
            $table->timestamp('sent_at')->nullable();
            $table->string('status')->default('pending');

            // Why a rung was skipped: quiet hours, a daily cap, spent credit,
            // an opt-out. This column is the first place to look when a
            // customer says the system went quiet.
            $table->string('skip_reason')->nullable();

            $table->timestamps();

            // The idempotency guard. Queues retry and schedulers overlap, and
            // no amount of care in PHP prevents a double send — only this does.
            $table->unique(['task_id', 'step']);
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_follow_ups');
    }
};
