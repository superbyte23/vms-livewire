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
        'firstname',
        'middlename',
        'lastname',
        'phone',
        'email',
        'photo',
        'company',
        'address',
        'government_id',
        'government_id_photo',
        'is_flagged',
        'notes',
        'qr_code_token',
    ];

    /**
     * Build the display/search `name` from parts. Every write path composes
     * it explicitly so `name` never drifts from the parts.
     */
    public static function composeName(?string $firstname, ?string $middlename, ?string $lastname): string
    {
        return implode(' ', array_filter([$firstname, $middlename, $lastname]));
    }

    /**
     * Split a full name into parts (first / middle / last).
     *
     * @return array{firstname: ?string, middlename: ?string, lastname: ?string}
     */
    public static function splitName(string $fullName): array
    {
        $tokens = preg_split('/\s+/', trim($fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return [
            'firstname' => $tokens[0] ?? null,
            'middlename' => count($tokens) > 2 ? implode(' ', array_slice($tokens, 1, -1)) : null,
            'lastname' => count($tokens) > 1 ? end($tokens) : null,
        ];
    }

    public function fullName(): string
    {
        return $this->name;
    }

    protected function casts(): array
    {
        return [
            'is_flagged' => 'boolean',
        ];
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class, 'visitor_id');
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
            'name', 'firstname', 'middlename', 'lastname', 'email', 'company', 'phone', 'address', 'government_id',
        ], 'like', '%'.$term.'%');
    }
}
