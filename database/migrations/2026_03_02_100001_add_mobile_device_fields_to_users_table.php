<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('expo_push_token', 255)->nullable()->after('documents_status');
            $table->string('app_version_last_seen', 40)->nullable()->after('expo_push_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['expo_push_token', 'app_version_last_seen']);
        });
    }
};

