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
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'invitation_token')) {
                $table->string('invitation_token', 64)->nullable()->unique()->after('remember_token');
            }

            if (!Schema::hasColumn('users', 'invitation_token_expires_at')) {
                $table->timestamp('invitation_token_expires_at')->nullable()->after('invitation_token');
            }

            if (!Schema::hasColumn('users', 'invited_at')) {
                $table->timestamp('invited_at')->nullable()->after('invitation_token_expires_at');
            }

            if (!Schema::hasColumn('users', 'invited_by')) {
                $table->uuid('invited_by')->nullable()->after('invited_at');
                $table->foreign('invited_by')->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'invited_by')) {
                $table->dropForeign(['invited_by']);
                $table->dropColumn('invited_by');
            }

            if (Schema::hasColumn('users', 'invited_at')) {
                $table->dropColumn('invited_at');
            }

            if (Schema::hasColumn('users', 'invitation_token_expires_at')) {
                $table->dropColumn('invitation_token_expires_at');
            }

            if (Schema::hasColumn('users', 'invitation_token')) {
                $table->dropColumn('invitation_token');
            }
        });
    }
};
