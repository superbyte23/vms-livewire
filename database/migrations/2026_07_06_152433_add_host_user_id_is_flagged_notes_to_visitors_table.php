<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->foreignId('host_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_flagged')->default(false);
            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('host_user_id');
            $table->dropColumn(['is_flagged', 'notes']);
        });
    }
};
