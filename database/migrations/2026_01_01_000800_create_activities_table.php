<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The organisational memory. Every state change lands here, which is what
     * makes the weekly report possible and what makes leaving expensive after
     * a customer has been on the product for a few months.
     */
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            // Null when the actor is the engine rather than a person, which is
            // most of the interesting rows.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->morphs('subject');
            $table->string('event');
            $table->json('properties')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['workspace_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
