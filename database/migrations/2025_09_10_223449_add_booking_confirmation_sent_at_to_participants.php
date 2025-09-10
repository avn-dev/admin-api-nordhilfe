<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participants', function (Blueprint $table) {
            $table->timestamp('booking_confirmation_sent_at')
                ->nullable()
                ->after('passport_photos');
        });
    }

    public function down(): void
    {
        // defensiv: nur droppen, wenn Spalte existiert
        if (Schema::hasColumn('participants', 'booking_confirmation_sent_at')) {
            Schema::table('participants', function (Blueprint $table) {
                $table->dropColumn('booking_confirmation_sent_at');
            });
        }
    }
};
