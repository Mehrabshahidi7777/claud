<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Meeting notes, kept rather than thrown away after the tasks are pulled
     * out of them.
     *
     * Two reasons. The extraction rules will be wrong in the first months and
     * the raw text is the only way to re-run them against real notes. And a
     * searchable record of what was decided, months back, is the thing that
     * makes leaving the product expensive.
     */
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            $table->string('title');
            $table->dateTime('held_at');

            // What was pasted in, untouched.
            $table->longText('notes');

            // What the model made of it. Null when no model was configured —
            // the notes are still worth keeping.
            $table->text('summary')->nullable();
            $table->json('decisions')->nullable();
            $table->boolean('processed_by_ai')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'held_at']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            // Where a task came from. A manager asking "why is this on my
            // list" gets the meeting it was agreed in.
            $table->foreignId('meeting_id')->nullable()->after('creator_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('meeting_id');
        });

        Schema::dropIfExists('meetings');
    }
};
