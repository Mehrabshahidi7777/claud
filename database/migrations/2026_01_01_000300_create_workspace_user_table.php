<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('member');

            // Where an escalation lands. Null means the workspace owner picks
            // it up, which is the right answer for a fifteen-person company
            // with no real hierarchy.
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();

            // A member on leave keeps their tasks and their place in reports,
            // but the ladder stops texting them until they are back.
            $table->timestamp('away_until')->nullable();

            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
            $table->index(['workspace_id', 'manager_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_user');
    }
};
