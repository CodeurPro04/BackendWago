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
            $table->string('phone')->nullable()->unique()->after('email');
            $table->string('role')->default('customer')->after('phone');
            $table->boolean('is_available')->default(false)->after('role');
            $table->unsignedInteger('wallet_balance')->default(0)->after('is_available');
            $table->decimal('latitude', 10, 7)->nullable()->after('wallet_balance');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone',
                'role',
                'is_available',
                'wallet_balance',
                'latitude',
                'longitude',
            ]);
        });
    }
};
