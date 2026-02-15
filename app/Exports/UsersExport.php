<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\User;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export class for generating user data Excel files.
 *
 * Handles exporting user data with optional filtering by role and status.
 */
final class UsersExport implements FromQuery, WithHeadings, WithMapping, WithStyles
{
    /**
     * The organization ID to filter users by.
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
     * @param int $organizationId The organization to export users from
     * @param array $filters Optional filters (role_id, is_active)
     */
    public function __construct($organizationId, array $filters = [])
    {
        $this->organizationId = $organizationId;
        $this->filters = $filters;
    }

    /**
     * Query for users to export
     */
    public function query(): \Illuminate\Database\Eloquent\Builder
    {
        $query = User::query()
            ->with(['roles'])
            ->forOrganization($this->organizationId);

        // Apply filters
        if (!empty($this->filters['role_id'])) {
            $query->whereHas('roles', function ($q) {
                $q->where('roles.id', $this->filters['role_id']);
            });
        }

        if (!empty($this->filters['is_active']) && $this->filters['is_active'] !== 'all') {
            $query->where('is_active', $this->filters['is_active'] === 'active');
        }

        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Define the column headings
     */
    public function headings(): array
    {
        return [
            'ID',
            'Name',
            'Email',
            'Roles',
            'Status',
            'Email Verified',
            'Created At',
            'Last Login',
        ];
    }

    /**
     * Map each user to the export format
     */
    public function map($user): array
    {
        return [
            $user->id,
            $user->name,
            $user->email,
            $user->roles->pluck('name')->implode(', '),
            $user->is_active ? 'Active' : 'Inactive',
            $user->email_verified_at ? 'Yes' : 'No',
            $user->created_at->format('Y-m-d H:i:s'),
            $user->last_login_at ? $user->last_login_at->format('Y-m-d H:i:s') : 'Never',
        ];
    }

    /**
     * Style the worksheet
     */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
