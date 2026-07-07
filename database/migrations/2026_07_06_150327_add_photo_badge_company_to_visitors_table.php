<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->string('photo')->nullable()->after('purpose');
            $table->string('badge_number')->nullable()->after('photo');
            $table->string('company')->nullable()->after('badge_number');
        });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->dropColumn(['photo', 'badge_number', 'company']);
        });
    }
};
