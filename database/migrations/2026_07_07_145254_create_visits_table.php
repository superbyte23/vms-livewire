<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visitor_id')->constrained('visitors')->cascadeOnDelete();
            $table->string('host')->nullable();
            $table->foreignId('host_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('purpose')->nullable();
            $table->string('visit_type')->nullable();
            $table->date('expected_date')->nullable();
            $table->longText('photo')->nullable();
            $table->longText('checkout_photo')->nullable();
            $table->string('badge_number')->nullable();
            $table->string('qr_code_token', 64)->nullable()->unique();
            $table->string('status')->default('checked_in');
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
