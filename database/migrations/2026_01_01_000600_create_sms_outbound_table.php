<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_outbound', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_follow_up_id')->nullable()->constrained()->nullOnDelete();

            $table->string('phone', 15);

            // The pattern key from config/sms.php plus the code actually sent.
            // Both are kept: the key survives a pattern being re-registered,
            // the code is what the provider's own logs will show.
            $table->string('pattern_key');
            $table->string('pattern_code')->nullable();
            $table->json('tokens')->nullable();

            // What the message would read as, rendered from the preview. The
            // dedicated line transmits the pattern, not this — it exists so a
            // human reading the ledger can see what arrived.
            $table->text('rendered_preview')->nullable();

            // Persian text is UCS-2: 70 characters in one message, 67 per part
            // after that. This is the billing unit, so it is stored, not
            // recomputed later from text that may have changed.
            $table->unsignedTinyInteger('segments')->default(1);

            $table->string('provider_message_id')->nullable();
            $table->string('status')->default('queued');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_outbound');
    }
};
