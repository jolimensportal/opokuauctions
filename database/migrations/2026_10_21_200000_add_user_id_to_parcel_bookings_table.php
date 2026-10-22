<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parcel_bookings', function (Blueprint $table): void {
            // A signed-in buyer's booking belongs to them. Email is not a
            // link: it is a string somebody typed, and it can be wrong. Null
            // for a guest, whose booking is only reachable by its email.
            $table->foreignId('user_id')
                ->nullable()
                ->after('email')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('parcel_bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
