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
        Schema::create('payments', function (Blueprint $table) {
        $table->id();
        $table->foreignId('participant_id')->constrained()->onDelete('cascade');

        $table->enum('method', ['paypal', 'cash']);
        $table->enum('status', ['unpaid', 'paid', 'refunded'])->default('unpaid');

        $table->decimal('amount', 8, 2);
        $table->string('currency')->default('EUR');

        $table->string('external_id')->nullable(); // z. B. PayPal txn_id
        $table->text('meta')->nullable(); // z. B. full PayPal response
        $table->timestamps();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
