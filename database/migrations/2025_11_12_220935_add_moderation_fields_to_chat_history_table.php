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
        Schema::table('chat_history', function (Blueprint $table) {
            $table->boolean('flagged')->default(false)->after('session_id');
            $table->boolean('reviewed')->default(false)->after('flagged');
            $table->foreignId('flagged_by')->nullable()->after('reviewed')->constrained('users')->onDelete('set null');
            $table->foreignId('reviewed_by')->nullable()->after('flagged_by')->constrained('users')->onDelete('set null');
            $table->timestamp('flagged_at')->nullable()->after('flagged_by');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('flag_reason')->nullable()->after('reviewed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_history', function (Blueprint $table) {
            $table->dropForeign(['flagged_by']);
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn([
                'flagged',
                'reviewed',
                'flagged_by',
                'reviewed_by',
                'flagged_at',
                'reviewed_at',
                'flag_reason',
            ]);
        });
    }
};
