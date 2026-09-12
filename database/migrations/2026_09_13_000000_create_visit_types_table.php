<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Snapshot of Visit::VISIT_TYPES at extraction time — these seed the
     * manageable list so fresh installs match previous behaviour.
     */
    private const DEFAULTS = [
        'Courtesy Visit',
        'Internship',
        'Training/Workshop',
        'Tour',
        'Event Attendance',
        'Consultation',
        'Document Submission',
        'Press Conference',
        'Delivery Personnel',
        'Interviewee',
        'Contractor',
        'Vendor',
        'Media Coverage',
        'Other',
    ];

    public function up(): void
    {
        Schema::create('visit_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();

        DB::table('visit_types')->insert(
            collect(self::DEFAULTS)->map(fn ($name, $index) => [
                'id' => (string) Str::uuid(),
                'name' => $name,
                'is_active' => true,
                'sort_order' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_types');
    }
};
