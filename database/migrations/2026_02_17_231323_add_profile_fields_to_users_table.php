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
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('avatar_url')->nullable()->after('last_name');
            $table->text('bio')->nullable()->after('avatar_url');
            $table->string('membership')->default('Standard')->after('bio');
            $table->decimal('rating', 3, 2)->default(5.00)->after('membership');
            $table->string('profile_status')->default('pending')->after('rating');
            $table->unsignedTinyInteger('account_step')->default(0)->after('profile_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'first_name',
                'last_name',
                'avatar_url',
                'bio',
                'membership',
                'rating',
                'profile_status',
                'account_step',
            ]);
        });
    }
};
