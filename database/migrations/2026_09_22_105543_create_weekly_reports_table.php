<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A generated report, kept rather than recomputed.
     *
     * Two reasons it is stored. The numbers must not move after the fact — a
     * manager who reads "۷۱٪ به‌موقع" on Saturday and sees a different figure on
     * Tuesday stops trusting the report. And email deliverability in Iran is
     * unreliable enough that the report needs a permanent address of its own,
     * which an SMS can carry when the email does not arrive.
     */
    public function up(): void
    {
        Schema::create('weekly_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->date('period_start');
            $table->date('period_end');

            // The frozen snapshot: every figure as it stood when the report ran.
            $table->json('metrics');

            // The prose summary. Written by the local model where one is
            // configured, and by a deterministic composer where it is not — the
            // column cannot tell the difference, which is the point.
            $table->text('narrative')->nullable();
            $table->boolean('narrative_from_ai')->default(false);

            // Unguessable, so the link can be texted to a manager who is not
            // signed in on the device they are reading it on.
            $table->string('share_token', 64)->unique();

            $table->timestamp('emailed_at')->nullable();
            $table->timestamp('sms_notified_at')->nullable();
            $table->timestamps();

            // One report per workspace per period. This is what makes an
            // hourly sweep safe: a second run for the same week is refused by
            // the database rather than by a check that can race.
            $table->unique(['workspace_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_reports');
    }
};
