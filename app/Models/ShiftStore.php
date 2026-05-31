<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftStore extends Model
{
    use HasFactory, HasUuids;

    protected $connection = 'mysql_ops';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'shift_start_time',
        'shift_end_time',
        'duration',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'duration' => 'integer',
    ];

    /**
     * Tenant that owns the shift configuration.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Ensure start time is serialised as HH:MM.
     */
    protected function shiftStartTime(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->formatTime($value),
        );
    }

    /**
     * Ensure end time is serialised as HH:MM.
     */
    protected function shiftEndTime(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $this->formatTime($value),
        );
    }

    /**
     * Format a stored time value to HH:MM.
     */
    private function formatTime($value): ?string
    {
        if (! $value) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->format('H:i');
        }

        $stringValue = trim((string) $value);

        $parsed = $this->tryParseUsingFormat($stringValue, 'H:i:s')
            ?? $this->tryParseUsingFormat($stringValue, 'H:i');

        if (! $parsed) {
            try {
                $parsed = CarbonImmutable::parse($stringValue, 'UTC');
            } catch (\Throwable $exception) {
                return $stringValue;
            }
        }

        return $parsed->format('H:i');
    }

    private function tryParseUsingFormat(string $value, string $format): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat($format, $value, 'UTC');
        } catch (\Throwable $exception) {
            return null;
        }
    }
}
