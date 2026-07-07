<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->string('valid_id_number')->nullable()->after('company');
        });

        Schema::table('visitor_logs', function (Blueprint $table) {
            $table->string('photo')->nullable()->after('purpose');
        });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->dropColumn('valid_id_number');
        });

        Schema::table('visitor_logs', function (Blueprint $table) {
            $table->dropColumn('photo');
        });
    }
};
