<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();

            // Sequential and human readable, because it is quoted in emails
            // and on bank statements.
            $table->string('number')->unique();

            $table->string('plan_key');
            $table->unsignedSmallInteger('seats');
            $table->string('term');

            // Every amount in Rial, the unit the gateway works in. Storing
            // Toman is how a factor of ten reaches an invoice.
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('vat');
            $table->unsignedBigInteger('total');
            $table->unsignedSmallInteger('vat_percent');

            $table->date('period_start');
            $table->date('period_end');

            // unpaid → paid, or void. Never back again: a paid invoice that
            // can return to unpaid is a refund, and a refund is a new record.
            $table->string('status')->default('unpaid');
            $table->timestamp('paid_at')->nullable();

            // Billing details the finance department needs on a formal
            // invoice. Filled by the customer, not by us.
            $table->string('legal_name')->nullable();
            $table->string('national_id')->nullable();
            $table->string('economic_code')->nullable();
            $table->text('address')->nullable();

            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
