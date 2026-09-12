<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Visit extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_CHECKED_IN = 'checked_in';

    public const STATUS_CHECKED_OUT = 'checked_out';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Default visit types seeded into the manageable `visit_types` table.
     * Runtime dropdowns read VisitType::options() instead.
     */
    public const VISIT_TYPES = [
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

    protected $fillable = [
        'visitor_id',
        'host',
        'host_user_id',
        'purpose',
        'visit_type',
        'expected_date',
        'photo',
        'checkout_photo',
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
            'expected_date' => 'date',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
        ];
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class)->withTrashed();
    }

    public function hostUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function scopeCheckedIn(Builder $query): void
    {
        $query->where('status', 'checked_in');
    }

    public function scopeScheduled(Builder $query): void
    {
        $query->where('status', 'scheduled');
    }

    /**
     * Real past visits only — excludes future bookings and cancellations.
     */
    public function scopeHistory(Builder $query): void
    {
        $query->whereIn('status', ['checked_in', 'checked_out']);
    }

    /**
     * A visitor may hold only one active booking at a time — booking again
     * while one is scheduled is blocked with a warning instead.
     */
    public static function hasActiveBooking(string $visitorId): bool
    {
        return static::scheduled()->where('visitor_id', $visitorId)->exists();
    }

    public static function activeBookingFor(string $visitorId): ?self
    {
        return static::scheduled()->with('visitor')->where('visitor_id', $visitorId)->first();
    }

    public static function isOnSite(string $visitorId): bool
    {
        return static::checkedIn()->where('visitor_id', $visitorId)->exists();
    }

    public function scopeFlagged(Builder $query): void
    {
        $query->whereHas('visitor', fn ($q) => $q->withTrashed()->where('is_flagged', true));
    }

    public function scopeSearch(Builder $query, string $term): void
    {
        $query->whereHas('visitor', fn ($q) => $q->withTrashed()->search($term));
    }

    public function scopeBetweenDates(Builder $query, string $from, string $to): void
    {
        $query->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to);
    }
}
