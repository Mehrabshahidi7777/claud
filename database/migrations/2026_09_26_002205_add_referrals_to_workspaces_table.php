<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who brought whom.
     *
     * The code is handed out the first time an owner opens the referral page,
     * so it is nullable rather than backfilled. The reward timestamp is what
     * makes the referrer's gift a one-off: it is claimed with a conditional
     * update, and only the request that flips it from null pays out.
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('referral_code', 16)->nullable()->unique()->after('type');
            $table->foreignId('referred_by_workspace_id')->nullable()->after('referral_code')
                ->constrained('workspaces')->nullOnDelete();
            $table->dateTime('referral_rewarded_at')->nullable()->after('referred_by_workspace_id');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_workspace_id');
            $table->dropColumn(['referral_code', 'referral_rewarded_at']);
        });
    }
};
