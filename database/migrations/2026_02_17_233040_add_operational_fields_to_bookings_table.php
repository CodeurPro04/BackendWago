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
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedTinyInteger('customer_rating')->nullable()->after('cancelled_reason');
            $table->text('customer_review')->nullable()->after('customer_rating');
            $table->json('before_photos')->nullable()->after('customer_review');
            $table->json('after_photos')->nullable()->after('before_photos');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'customer_rating',
                'customer_review',
                'before_photos',
                'after_photos',
            ]);
        });
    }
};
