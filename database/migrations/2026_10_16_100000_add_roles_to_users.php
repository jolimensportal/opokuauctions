<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles, and the panel access rule that follows from them.
 *
 * Three roles, and the split exists for one reason: only an Owner may release
 * funds from a disputed state. Ops can create every operational problem and
 * cannot resolve a financial one alone. With a solo dev this costs nothing to
 * set up now and is close to impossible to retrofit, because retrofitting means
 * auditing who released what, retroactively.
 *
 * Buyers are deliberately NOT admin users. A buyer must never be able to reach
 * the Filament panel, so `canAccessPanel` returns false for them rather than
 * relying on hiding routes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('buyer')->after('password');
            $table->string('phone')->nullable()->after('email');
            $table->string('country_code', 2)->nullable()->after('phone');
        });

        // Backfill: anyone who existed before roles were a buyer. Admins are
        // promoted explicitly rather than being guessed at.
        \DB::table('users')->whereNull('role')->update(['role' => 'buyer']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'phone', 'country_code']);
        });
    }
};