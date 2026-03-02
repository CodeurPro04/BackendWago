<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 40)->default('driver_system');
            $table->string('title', 200);
            $table->text('body');
            $table->string('audience', 40)->default('all');
            $table->string('route', 255)->nullable();
            $table->integer('sent_count')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['channel', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_announcements');
    }
};

