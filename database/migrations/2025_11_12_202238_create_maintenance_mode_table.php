<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('maintenance_mode', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->text('message')->nullable();
            $table->timestamp('scheduled_start')->nullable();
            $table->timestamp('scheduled_end')->nullable();
            $table->timestamps();
        });

        // Create maintenance mode allowed IPs table
        Schema::create('maintenance_mode_allowed_ips', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address', 45)->unique();
            $table->text('description')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('added_at')->useCurrent();
            $table->timestamps();
        });

        // Insert default maintenance mode settings
        DB::table('maintenance_mode')->insert([
            [
                'enabled' => false,
                'message' => 'We are currently performing scheduled maintenance. Please check back shortly.',
                'scheduled_start' => null,
                'scheduled_end' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('maintenance_mode_allowed_ips');
        Schema::dropIfExists('maintenance_mode');
    }
};
