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
        Schema::create('security_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Create IP whitelist table
        Schema::create('ip_whitelist', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address', 45)->unique();
            $table->text('description')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('added_at')->useCurrent();
            $table->timestamps();
        });

        // Insert default security settings
        DB::table('security_settings')->insert([
            [
                'key' => 'password_min_length',
                'value' => '8',
                'description' => 'Minimum password length',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'password_require_uppercase',
                'value' => '1',
                'description' => 'Require uppercase letters in passwords',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'password_require_lowercase',
                'value' => '1',
                'description' => 'Require lowercase letters in passwords',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'password_require_numbers',
                'value' => '1',
                'description' => 'Require numbers in passwords',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'password_require_symbols',
                'value' => '0',
                'description' => 'Require special characters in passwords',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'session_timeout',
                'value' => '120',
                'description' => 'Session timeout in minutes',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'session_timeout_enabled',
                'value' => '1',
                'description' => 'Enable session timeout',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'ip_whitelist_enabled',
                'value' => '0',
                'description' => 'Enable IP whitelist for admin access',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'security_alerts_enabled',
                'value' => '1',
                'description' => 'Enable security alerts',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'security_alert_email',
                'value' => '',
                'description' => 'Email address for security alerts',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'failed_login_attempts_limit',
                'value' => '5',
                'description' => 'Maximum failed login attempts before lockout',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'failed_login_lockout_duration',
                'value' => '15',
                'description' => 'Account lockout duration in minutes after failed login attempts',
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
        Schema::dropIfExists('ip_whitelist');
        Schema::dropIfExists('security_settings');
    }
};
