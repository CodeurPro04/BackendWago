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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->string('service');
            $table->string('vehicle');
            $table->string('wash_type_key');
            $table->string('address');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('price');
            $table->string('scheduled_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('customer_phone')->nullable();
            $table->timestamp('driver_arrived_at')->nullable();
            $table->timestamp('wash_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('cancelled_reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
