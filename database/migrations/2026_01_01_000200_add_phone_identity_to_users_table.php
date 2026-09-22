<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The phone number is the identity, not a profile field: sign-up, sign-in
     * and every follow-up run through it. Email and password stay on the table
     * as optional columns so an emailed weekly report, an invoice, and later
     * SSO can be added without a painful migration.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Normalised to 98XXXXXXXXXX before it ever reaches the database,
            // so the unique index actually means one person.
            $table->string('phone', 15)->unique()->after('id');
            $table->timestamp('phone_verified_at')->nullable()->after('phone');

            // Set when someone replies "قطع" to a message. The engine still
            // tracks their tasks; it just stops texting them, and tells their
            // manager that it has.
            $table->timestamp('sms_opted_out_at')->nullable()->after('phone_verified_at');

            $table->softDeletes();
        });

        // A user created by their manager has a phone and a name and nothing
        // else, so the columns the default skeleton requires must give way.
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropColumn(['phone', 'phone_verified_at', 'sms_opted_out_at', 'deleted_at']);
        });
    }
};
