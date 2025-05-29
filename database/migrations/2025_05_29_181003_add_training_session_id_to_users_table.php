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
        Schema::table('participants', function (Blueprint $table) {
            $table->boolean('attended')->default(false)->after('phone');
            $table->unsignedBigInteger('training_session_id')->nullable()->after('id');

            $table->foreign('training_session_id')
                  ->references('id')
                  ->on('training_sessions')
                  ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('participants', function (Blueprint $table) {
            $table->dropForeign(['training_session_id']);
            $table->dropColumn('attended');
            $table->dropColumn('training_session_id');
        });
    }
};
