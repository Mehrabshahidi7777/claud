<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();

            // Nullable by design: an unassigned task is chased through its
            // creator rather than falling silently into a gap.
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();

            // Stored in UTC like every timestamp here; quiet hours and the
            // ladder are computed in the workspace timezone at read time.
            $table->timestamp('due_at')->nullable();

            $table->string('priority')->default('normal');
            $table->string('status')->default('open');

            // Set only when the manager explicitly allowed a critical task to
            // break quiet hours. Priority alone is not consent.
            $table->boolean('may_break_quiet_hours')->default(false);

            // A second deferral stops being an excuse and becomes a signal, so
            // the count is part of the task rather than buried in the log.
            $table->unsignedSmallInteger('defer_count')->default(0);
            $table->text('defer_reason')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The sweep's hot path: open work in one workspace, by deadline.
            $table->index(['workspace_id', 'status', 'due_at']);
            $table->index(['assignee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
