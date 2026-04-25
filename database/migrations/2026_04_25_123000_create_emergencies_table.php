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
        Schema::create('emergencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['fire', 'attack', 'medical', 'kidnap', 'other']);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('low');
            $table->json('ai_response')->nullable();
            $table->enum('status', ['active', 'resolved'])->default('active');
            $table->timestamps();

            $table->index(['latitude', 'longitude', 'created_at'], 'emergency_geo_time_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emergencies');
    }
};

