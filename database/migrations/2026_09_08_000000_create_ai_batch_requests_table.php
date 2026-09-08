<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_batch_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('batch_id');
            $table->string('provider');
            $table->string('custom_id');
            $table->uuid('invocation_id');
            $table->boolean('structured')->default(false);
            $table->string('agent')->nullable();
            $table->string('model')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['batch_id', 'provider', 'custom_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_batch_requests');
    }
};
