<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One attempt at paying an invoice. Attempts are kept whether they
     * succeeded or not — a customer saying "the money left my account" is
     * answered from this table.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('gateway');

            // The amount we asked the bank for. Verification compares the
            // bank's own figure against this one, and a mismatch is refused
            // — that check is what stops someone paying 1,000 Rial for a
            // 5,000,000 Rial plan by editing the request.
            $table->unsignedBigInteger('amount');

            // Our reference, sent as ResNum and returned untouched. Unique so
            // a replayed callback cannot open a second payment.
            $table->string('res_num')->unique();

            $table->string('token')->nullable();

            // The bank's reference, arriving with the callback. Unique across
            // the table: the same reference must never settle twice, however
            // many times the callback is delivered.
            $table->string('ref_num')->nullable()->unique();

            $table->string('trace_no')->nullable();
            $table->string('rrn')->nullable();
            $table->string('card_masked', 32)->nullable();

            // pending → paid → verified, or failed. Only `verified` releases
            // the subscription.
            $table->string('status')->default('pending');
            $table->string('failure_reason')->nullable();

            // Everything the gateway sent, kept verbatim. When a dispute
            // arrives months later this is the only account of what happened.
            $table->json('callback_payload')->nullable();
            $table->json('verify_payload')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
