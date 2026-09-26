<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The companies that pay for پیگیر so its users do not have to.
     *
     * Entered by the platform owner in the admin panel after talking to each
     * one. Clicks are counted because "this many people opened your site from
     * پیگیر this month" is what renews a sponsorship.
     */
    public function up(): void
    {
        Schema::create('sponsors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('description', 400)->nullable();
            $table->string('website_url', 255)->nullable();
            $table->string('logo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsors');
    }
};
