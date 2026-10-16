<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');                      // e.g., "Nepal Rastra Bank Notices"
            $table->string('url')->unique();             // RSS feed URL or website URL
            $table->string('type')->default('rss');      // 'rss' or 'html'
            $table->boolean('is_active')->default(true); // Toggle source on/off
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
