<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('firstname')->nullable();
            $table->string('middlename')->nullable();
            $table->string('lastname')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->longText('photo')->nullable();
            $table->string('company')->nullable();
            $table->string('address')->nullable();
            $table->string('government_id')->nullable();
            $table->longText('government_id_photo')->nullable();
            $table->string('qr_code_token', 64)->nullable()->unique();
            $table->boolean('is_flagged')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitors');
    }
};
