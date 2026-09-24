<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which of the three products this workspace is.
     *
     * Defaults to corporate so every workspace that already exists keeps
     * exactly what it had — the type decides what is visible, and quietly
     * taking modules away from a live customer is not a migration anybody
     * should write.
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('type', 20)->default('corporate')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
