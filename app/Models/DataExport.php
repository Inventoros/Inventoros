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
     * Whether the generated file exists and can be downloaded.
     */
    public function isDownloadable(): bool
    {
        return $this->status === 'completed'
            && $this->path !== null
            && Storage::disk($this->disk)->exists($this->path);
    }
}
