<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('origin_zones', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->enum('country', ['us', 'ca']);
            $table->string('code');
            $table->string('name');
            $table->boolean('active')->default(true);

            $table->unique(['country', 'code']);
        });

        Schema::create('postcode_bands', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->enum('country', ['us', 'ca']);
            // Matched against the START of the normalised postcode, so "M5V"
            // is one band and "M4B" is another.
            $table->string('prefix');
            // A string, not foreignId(): origin_zones uses a string primary key,
            // so foreignId() would create a bigint column that can never match
            // the key it references.
            $table->string('zone_id');
            $table->foreign('zone_id')->references('id')->on('origin_zones')->cascadeOnDelete();
            $table->boolean('active')->default(true);

            // Resolution matches on country + prefix and takes the longest
            // match, so this index is the one the lookup actually uses.
            $table->index(['country', 'prefix'], 'postcode_bands_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postcode_bands');
        Schema::dropIfExists('origin_zones');
    }
};