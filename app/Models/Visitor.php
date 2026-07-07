<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Visitor extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'photo',
        'company',
        'valid_id_number',
        'is_flagged',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_flagged' => 'boolean',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(VisitorLog::class, 'visitor_id');
    }

    public function hostUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function scopeFlagged(Builder $query): void
    {
        $query->where('is_flagged', true);
    }

    public function scopeSearch(Builder $query, string $term): void
    {
        $query->whereAny([
            'name', 'email', 'company', 'phone', 'valid_id_number',
        ], 'like', '%'.$term.'%');
    }
}
