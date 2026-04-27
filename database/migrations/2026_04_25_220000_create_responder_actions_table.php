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
        Schema::create('responder_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emergency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('responder_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['accepted', 'rejected']);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['emergency_id', 'responder_id'], 'responder_action_unique_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('responder_actions');
    }
};

