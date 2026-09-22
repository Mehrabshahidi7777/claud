<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every inbound message is stored raw first and interpreted second. When
     * the matching rules turn out to be wrong — and in the first months they
     * will be — this table is what lets them be replayed against real traffic
     * instead of guesses.
     */
    public function up(): void
    {
        Schema::create('sms_inbound', function (Blueprint $table) {
            $table->id();
            $table->string('from_phone', 15);
            $table->text('body');
            $table->text('normalized_body')->nullable();
            $table->json('raw')->nullable();

            // Providers redeliver. This is the only thing standing between a
            // retry and a task being closed twice.
            $table->string('provider_message_id')->unique();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('matched_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('interpreted_as')->nullable();

            // False when the reply arrived after the task had already closed,
            // or from a number belonging to nobody. Recorded, not acted on.
            $table->boolean('applied')->default(false);
            $table->string('ignored_reason')->nullable();

            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['from_phone', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_inbound');
    }
};
