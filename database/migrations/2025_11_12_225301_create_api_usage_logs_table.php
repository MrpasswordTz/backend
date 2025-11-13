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
        Schema::create('api_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->string('api_provider')->index(); // 'huggingface', 'openai', etc.
            $table->string('endpoint')->nullable(); // API endpoint called
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('model')->nullable(); // Model used
            $table->integer('input_tokens')->default(0); // Input tokens used
            $table->integer('output_tokens')->default(0); // Output tokens used
            $table->integer('total_tokens')->default(0); // Total tokens used
            $table->integer('response_time_ms')->nullable(); // Response time in milliseconds
            $table->integer('status_code')->nullable(); // HTTP status code
            $table->boolean('success')->default(true); // Whether the request was successful
            $table->text('error_message')->nullable(); // Error message if failed
            $table->text('request_data')->nullable(); // Request data (JSON)
            $table->text('response_data')->nullable(); // Response data (JSON, truncated)
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->decimal('cost', 10, 6)->nullable(); // Cost in USD (if applicable)
            $table->timestamp('created_at')->useCurrent();
            $table->index(['api_provider', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('success');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_usage_logs');
    }
};
