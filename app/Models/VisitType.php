<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VisitType extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Names for visit-type dropdowns. Falls back to the model defaults when
     * the table is unavailable (e.g. pending migration).
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        try {
            $options = static::active()->ordered()->pluck('name')->all();

            if (! empty($options)) {
                return $options;
            }
        } catch (\Throwable) {
            // Fall through to defaults below.
        }

        return Visit::VISIT_TYPES;
    }

    public function isInUse(): bool
    {
        return Visit::where('visit_type', $this->name)->exists();
    }
}
