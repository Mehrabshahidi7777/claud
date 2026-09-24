<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money between friends.
     *
     * The friends plan existed as a price with nothing behind it — the same
     * screens as a household. Its actual pain is not chores, it is the
     * question after every trip and every restaurant: کی به کی چقدر بدهکار
     * است، and the fact that nobody wants to be the one who asks.
     *
     * Two tables and not one because a split is not a column: an expense one
     * person paid is owed by several, in shares that rarely divide evenly.
     */
    public function up(): void
    {
        Schema::create('shared_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            // Who actually paid. Everyone else in the shares owes them.
            $table->foreignId('payer_id')->constrained('users')->cascadeOnDelete();

            $table->string('title');
            $table->unsignedBigInteger('amount');
            $table->date('spent_on');
            $table->string('note', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'spent_on']);
        });

        Schema::create('shared_expense_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shared_expense_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Whole rial. The split is computed so these sum to the expense
            // exactly — a remainder silently dropped is a balance that never
            // reaches zero, and a group that can never finish settling up
            // stops trusting the numbers.
            $table->unsignedBigInteger('amount');

            $table->timestamps();

            $table->unique(['shared_expense_id', 'user_id']);
        });

        /*
        | Money actually handed over, which is the only thing that clears a
        | debt. Kept as its own record rather than as edits to the expenses,
        | so "چه کسی کِی چقدر داد" survives.
        */
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();

            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();

            $table->unsignedBigInteger('amount');
            $table->date('settled_on');
            $table->string('note', 500)->nullable();

            $table->timestamps();

            $table->index(['workspace_id', 'settled_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
        Schema::dropIfExists('shared_expense_shares');
        Schema::dropIfExists('shared_expenses');
    }
};
