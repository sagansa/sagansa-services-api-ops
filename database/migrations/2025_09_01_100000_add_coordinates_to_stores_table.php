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
        Schema::table('stores', function (Blueprint $table) {
            if (! Schema::hasColumn('stores', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('location');
            }

            if (! Schema::hasColumn('stores', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (Schema::hasColumn('stores', 'latitude')) {
                $table->dropColumn('latitude');
            }

            if (Schema::hasColumn('stores', 'longitude')) {
                $table->dropColumn('longitude');
            }
        });
    }
};
