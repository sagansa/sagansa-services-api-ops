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
            if (! Schema::hasColumn('stores', 'nickname')) {
                $table->string('nickname')->nullable()->after('name');
            }

            if (! Schema::hasColumn('stores', 'no_telp')) {
                $table->string('no_telp')->nullable()->after('nickname');
            }

            if (! Schema::hasColumn('stores', 'email')) {
                $table->string('email')->nullable()->after('no_telp');
            }

            if (! Schema::hasColumn('stores', 'status')) {
                $table->string('status')->default('active')->after('email');
            }

            if (! Schema::hasColumn('stores', 'radius')) {
                $table->unsignedInteger('radius')->default(100)->after('status');
            }

            if (Schema::hasColumn('stores', 'location')) {
                $table->dropColumn('location');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            if (! Schema::hasColumn('stores', 'location')) {
                $table->string('location')->nullable()->after('name');
            }

            $columnsToDrop = array_filter([
                Schema::hasColumn('stores', 'nickname') ? 'nickname' : null,
                Schema::hasColumn('stores', 'no_telp') ? 'no_telp' : null,
                Schema::hasColumn('stores', 'email') ? 'email' : null,
                Schema::hasColumn('stores', 'status') ? 'status' : null,
                Schema::hasColumn('stores', 'radius') ? 'radius' : null,
            ]);

            if (! empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
