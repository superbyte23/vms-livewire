<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        $rows = DB::table('visitors')->get()->map(fn ($r) => (array) $r)->toArray();
        $columns = ['id', 'name', 'phone', 'email', 'photo', 'company', 'is_flagged', 'notes', 'created_at', 'updated_at', 'deleted_at'];

        Schema::drop('visitors');

        Schema::create('visitors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('photo')->nullable();
            $table->string('company')->nullable();
            $table->boolean('is_flagged')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        foreach (array_chunk($rows, 100) as $chunk) {
            $insert = [];
            foreach ($chunk as $row) {
                $clean = array_intersect_key($row, array_flip($columns));
                $clean['is_flagged'] = (bool) ($clean['is_flagged'] ?? false);
                $insert[] = $clean;
            }
            DB::table('visitors')->insert($insert);
        }

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        $rows = DB::table('visitors')->get()->map(fn ($r) => (array) $r)->toArray();

        Schema::drop('visitors');

        Schema::create('visitors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('photo')->nullable();
            $table->string('company')->nullable();
            $table->boolean('is_flagged')->default(false);
            $table->text('notes')->nullable();
            $table->string('host')->nullable();
            $table->foreignId('host_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('purpose')->nullable();
            $table->string('badge_number')->nullable();
            $table->string('qr_code_token', 64)->nullable()->unique();
            $table->string('status')->default('checked_in');
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('visitors')->insert($chunk);
        }

        Schema::enableForeignKeyConstraints();
    }
};
