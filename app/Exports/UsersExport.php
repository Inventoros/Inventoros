<?php

namespace App\Exports;

use App\Models\User;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class UsersExport implements FromQuery, WithHeadings, WithMapping, WithStyles
{
    protected $organizationId;
    protected $filters;

    public function __construct($organizationId, array $filters = [])
    {
        $this->organizationId = $organizationId;
        $this->filters = $filters;
    }

    /**
     * Query for users to export
     */
    public function query()
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
    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
