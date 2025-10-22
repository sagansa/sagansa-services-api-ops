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
        Schema::table('tenants', function (Blueprint $table) {
            if (!Schema::hasColumn('tenants', 'owner_id')) {
                $table->uuid('owner_id')->nullable()->after('name');
                $table->unique('owner_id');
                $table->foreign('owner_id')->references('id')->on('users')->cascadeOnDelete();
            }
        });

        Schema::create('stores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->string('location')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stores');

        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'owner_id')) {
                $table->dropForeign('tenants_owner_id_foreign');
                $table->dropUnique('tenants_owner_id_unique');
                $table->dropColumn('owner_id');
            }
        });
    }
};
