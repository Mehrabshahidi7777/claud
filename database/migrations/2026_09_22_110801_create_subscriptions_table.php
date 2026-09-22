<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('plan_key');
            $table->unsignedSmallInteger('seats')->default(1);
            $table->string('term')->default('monthly');

            // trialing → active → grace → expired. Recurring card payments do
            // not exist for Iranian gateways, so a renewal is always a person
            // deciding to pay again — and `grace` is the week between them
            // forgetting and us locking the account.
            $table->string('status')->default('trialing');

            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Which reminders have gone out, so a sweep that runs hourly does
            // not text the same warning four times.
            $table->json('reminders_sent')->nullable();

            $table->timestamps();

            // One live subscription per workspace. A second would make "which
            // plan am I on" unanswerable.
            $table->unique(['workspace_id', 'status']);
            $table->index(['status', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
