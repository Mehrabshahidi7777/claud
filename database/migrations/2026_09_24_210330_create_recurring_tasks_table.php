<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Work that comes back round.
     *
     * One table, two very different customers. For a service company these
     * are maintenance contracts — the six-monthly chiller service that is
     * only ever sold when somebody remembers to ring the customer, which is
     * why most of them are never sold at all. For a family plan the same rows
     * are the car service, the insurance renewal and the quarterly bill.
     *
     * The mechanism is identical: something with a date that comes back, and
     * that nobody is holding. Only the wording on screen differs, so there is
     * no reason for two tables or two engines.
     */
    public function up(): void
    {
        Schema::create('recurring_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->string('description', 1000)->nullable();

            // Set for a customer service contract, empty for the household
            // kind. Its presence is what turns the list into a revenue view.
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 20)->nullable();

            $table->string('interval_unit', 10);
            $table->unsignedSmallInteger('interval_count')->default(1);
            $table->string('anchor', 20)->default('scheduled');

            // How many days ahead the task is raised. A service that appears
            // on the day it is due cannot be sold: somebody has to ring the
            // customer and agree a date first.
            $table->unsignedSmallInteger('lead_days')->default(7);

            $table->date('next_due_on');
            $table->date('last_done_on')->nullable();
            $table->unsignedInteger('occurrences')->default(0);

            // What one cycle is worth, in rial. Optional, and meaningless for
            // a household chore — but for a service company it is the whole
            // argument: the sum of these across overdue rows is revenue the
            // company is currently losing by forgetting.
            $table->unsignedBigInteger('estimated_value')->nullable();

            $table->string('priority', 20)->default('normal');
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'is_active', 'next_due_on']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('recurring_task_id')->nullable()->after('meeting_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurring_task_id');
        });

        Schema::dropIfExists('recurring_tasks');
    }
};
