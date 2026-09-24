<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The parts of a company: accounting, commercial, HR, and whatever else
     * the company's own work requires.
     *
     * A department is not a permission. Where somebody sits and what they may
     * do are different questions, and a system that fuses them cannot express
     * the ordinary case of a junior in the accounts department who records
     * expenses but does not see the receivables ledger.
     */
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('kind', 30);
            $table->string('name');

            // Whose department it is. Doubles as the fallback an escalation
            // climbs to when somebody has no named manager — better than the
            // owner, who in a company of forty has no idea what the task was.
            $table->foreignId('lead_id')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'is_active']);
        });

        Schema::table('workspace_user', function (Blueprint $table) {
            // Where this member sits. Null is legitimate — small companies
            // have no departments at all and should not be made to invent one.
            $table->foreignId('department_id')->nullable()->after('role')
                ->constrained()->nullOnDelete();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('contract_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });

        Schema::table('workspace_user', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });

        Schema::dropIfExists('departments');
    }
};
