<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('driver_account_type', 30)->nullable()->after('role');
            $table->string('company_name')->nullable()->after('last_name');
            $table->string('manager_name')->nullable()->after('company_name');
            $table->json('pricing')->nullable()->after('documents');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'driver_account_type',
                'company_name',
                'manager_name',
                'pricing',
            ]);
        });
    }
};
