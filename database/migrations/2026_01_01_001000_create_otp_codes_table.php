<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 15);

            // Hashed. A leaked database should not hand out live login codes,
            // short-lived though they are.
            $table->string('code_hash');

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();

            // Three wrong guesses locks this code out. Without it, a five digit
            // code is a few thousand requests away from being brute forced.
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->string('request_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['phone', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
