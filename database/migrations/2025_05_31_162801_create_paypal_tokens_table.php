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
        Schema::create('paypal_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->boolean('used')->default(false);
            $table->json('payload'); // enthält verschlüsselte Buchungsdaten
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paypal_tokens');
    }
};
