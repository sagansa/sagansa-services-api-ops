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
        Schema::create('attendances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id');
            $table->uuid('shift_store_id')->nullable();
            $table->string('status', 50)->default('pending');
            $table->string('image_in', 2048)->nullable();
            $table->timestamp('check_in')->nullable();
            $table->decimal('latitude_in', 10, 7)->nullable();
            $table->decimal('longitude_in', 10, 7)->nullable();
            $table->string('image_out', 2048)->nullable();
            $table->timestamp('check_out')->nullable();
            $table->decimal('latitude_out', 10, 7)->nullable();
            $table->decimal('longitude_out', 10, 7)->nullable();
            $table->uuid('created_by_id');
            $table->uuid('approved_by_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('shift_store_id')->references('id')->on('shift_stores')->nullOnDelete();
            $table->foreign('created_by_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('approved_by_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
