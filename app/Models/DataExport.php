<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Auth\Organization;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A queued export file generated for a user.
 *
 * Created when an export exceeds the synchronous row limit; the queued job
 * writes the file to the configured disk and flips status to completed (or
 * failed). The user downloads the finished file from the import/export page.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property string $type
 * @property string $filename
 * @property string $disk
 * @property string|null $path
 * @property array|null $filters
 * @property string $status
 * @property int|null $row_count
 * @property string|null $error
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DataExport extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'user_id',
        'type',
        'filename',
        'disk',
        'path',
        'filters',
        'status',
        'row_count',
        'error',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'row_count' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * The user who requested the export.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The organization that owns the export.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Days a finished export is kept, from config('exports.retention_days').
     * Zero or less turns expiry (and the exports:prune command) off.
     */
    public static function retentionDays(): int
    {
        return (int) config('exports.retention_days', 7);
    }

    /**
     * When this export stops being downloadable, or null if it never expires
     * (not completed yet, or retention is turned off).
     */
    public function expiresAt(): ?Carbon
    {
        $days = self::retentionDays();

        if ($days < 1 || $this->status !== 'completed') {
            return null;
        }

        $finishedAt = $this->completed_at ?? $this->created_at;

        return $finishedAt?->copy()->addDays($days);
    }

    /**
     * Whether the export is past its retention window. The file may still be
     * on disk until the next exports:prune run, but it is no longer served.
     */
    public function isExpired(): bool
    {
        $expiresAt = $this->expiresAt();

        return $expiresAt !== null && $expiresAt->lessThanOrEqualTo(now());
    }

    /**
     * Whether the generated file exists and can be downloaded.
     */
    public function isDownloadable(): bool
    {
        return $this->status === 'completed'
            && $this->path !== null
            && ! $this->isExpired()
            && Storage::disk($this->disk)->exists($this->path);
    }
}
