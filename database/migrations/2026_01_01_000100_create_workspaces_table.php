<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('timezone')->default('Asia/Tehran');

            // Per-workspace overrides of config/followup.php. Anything absent
            // falls back to the config default, so an empty object is valid.
            $table->json('settings')->nullable();

            // The workspace-level kill switch, separate from the global one in
            // config/sms.php: a customer closing for Nowruz turns this off.
            $table->boolean('sms_enabled')->default(true);

            // Credit is counted in messages, not parts. `sms_used` resets with
            // the billing period; the ledger in sms_outbound is the audit trail.
            $table->unsignedInteger('sms_quota')->default(500);
            $table->unsignedInteger('sms_used')->default(0);
            $table->timestamp('sms_period_started_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
