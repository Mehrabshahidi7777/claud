<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The money side, kept deliberately narrow.
     *
     * This is not a general ledger and does not try to be one — no journal
     * entries, no chart of accounts, no tax filing. Those belong to the
     * accounting package the company already owns, and a half-built version
     * of them would be worse than none.
     *
     * What lives here is the part the accounting package cannot do, because
     * it needs a follow-up engine: money that was approved and then spent,
     * and money that is owed and nobody is chasing.
     */
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            // The request that authorised it, where one exists. This is the
            // whole reason expenses live beside approvals: "who said yes to
            // this" is a question no accounting package can answer.
            $table->foreignId('approval_request_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('category', 20);
            $table->string('title');
            $table->string('vendor')->nullable();

            // Rial, as an integer. Rial has no subunit in practice, and float
            // money is a bug that only shows up once the numbers are large.
            $table->unsignedBigInteger('amount');

            $table->date('spent_on');
            $table->string('note', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'spent_on']);
            $table->index(['workspace_id', 'category']);
        });

        Schema::create('receivables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            // Who inside the company is responsible for collecting it. The
            // follow-up engine chases this person, not the customer — a
            // dedicated line that texts strangers about their debts is a
            // complaint to the regulator waiting to happen.
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('customer_name');
            $table->string('customer_phone', 20)->nullable();
            $table->string('title');

            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('settled_amount')->default(0);

            $table->date('issued_on');
            $table->date('due_on');

            $table->string('status', 20)->default('open');

            // The chase task the sweep raised for this, so a second sweep does
            // not raise a second one. Nullable because most receivables are
            // paid before anyone has to be chased about them.
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'status', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receivables');
        Schema::dropIfExists('expenses');
    }
};
