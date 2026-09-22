<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Official Iranian holidays. Without this table the engine texts people on
     * Nowruz, and a customer who gets chased during Nowruz does not renew.
     */
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('title');

            // Religious holidays move against the solar calendar, so each year
            // is seeded rather than derived.
            $table->unsignedSmallInteger('jalali_year')->nullable();
            $table->timestamps();

            $table->index('jalali_year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
