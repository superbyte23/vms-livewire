<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VisitorLog extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'visitor_id',
        'host',
        'host_user_id',
        'purpose',
        'photo',
        'badge_number',
        'qr_code_token',
        'status',
        'checked_in_at',
        'checked_out_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
        ];
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    public function hostUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function scopeCheckedIn(Builder $query): void
    {
        $query->where('status', 'checked_in');
    }

    public function scopeFlagged(Builder $query): void
    {
        $query->whereHas('visitor', fn ($q) => $q->where('is_flagged', true));
    }

    public function scopeSearch(Builder $query, string $term): void
    {
        $query->whereHas('visitor', fn ($q) => $q->search($term));
    }

    public function scopeBetweenDates(Builder $query, string $from, string $to): void
    {
        $query->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to);
    }
}
