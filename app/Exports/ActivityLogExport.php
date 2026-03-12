<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\ActivityLog;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export class for generating activity log data Excel/CSV files.
 *
 * Handles exporting activity log data with optional filtering by date range, user, and action type.
 */
final class ActivityLogExport implements FromQuery, WithHeadings, WithMapping, WithStyles
{
    /**
     * The organization ID to filter activity logs by.
     *
     * @var int
     */
    protected $organizationId;

    /**
     * Filters to apply to the export query.
     *
     * @var array
     */
    protected $filters;

    /**
     * Create a new export instance.
     *
     * @param int $organizationId The organization to export activity logs from
     * @param array $filters Optional filters (date_from, date_to, user_id, action)
     */
    public function __construct(int $organizationId, array $filters = [])
    {
        $this->organizationId = $organizationId;
        $this->filters = $filters;
    }

    /**
     * Query for activity logs to export.
     */
    public function query(): \Illuminate\Database\Eloquent\Builder
    {
        $query = ActivityLog::query()
            ->with('user')
            ->forOrganization($this->organizationId);

        if (!empty($this->filters['date_from'])) {
            $query->whereDate('created_at', '>=', $this->filters['date_from']);
        }

        if (!empty($this->filters['date_to'])) {
            $query->whereDate('created_at', '<=', $this->filters['date_to']);
        }

        if (!empty($this->filters['user_id'])) {
            $query->where('user_id', $this->filters['user_id']);
        }

        if (!empty($this->filters['action'])) {
            $query->where('action', $this->filters['action']);
        }

        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Define the column headings.
     */
    public function headings(): array
    {
        return [
            'Date',
            'User',
            'Action',
            'Description',
            'Subject Type',
            'Subject ID',
            'Changes',
        ];
    }

    /**
     * Map each activity log to the export format.
     */
    public function map($activityLog): array
    {
        $changes = '';
        if ($activityLog->properties) {
            $changesArray = $activityLog->changes;
            if (!empty($changesArray)) {
                $parts = [];
                foreach ($changesArray as $key => $value) {
                    if (isset($value['old'], $value['new'])) {
                        $parts[] = "{$key}: {$value['old']} -> {$value['new']}";
                    } else {
                        $parts[] = "{$key}: " . json_encode($value);
                    }
                }
                $changes = implode('; ', $parts);
            }
        }

        // Extract short class name from fully qualified subject_type
        $subjectType = $activityLog->subject_type
            ? class_basename($activityLog->subject_type)
            : '';

        return [
            $activityLog->created_at->format('Y-m-d H:i:s'),
            $activityLog->user ? $activityLog->user->name : 'System',
            $activityLog->action,
            $activityLog->description,
            $subjectType,
            $activityLog->subject_id,
            $changes,
        ];
    }

    /**
     * Style the worksheet.
     */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
