<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $query = ActivityLog::query()
            ->with(['user', 'organization'])
            ->forOrganization($request->user()->organization_id);

        // Filter by user
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by action
        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        // Filter by subject type
        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->subject_type);
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Search
        if ($request->filled('search')) {
            $query->where('description', 'like', '%' . $request->search . '%');
        }

        $activities = $query->latest()
            ->paginate(config('limits.pagination.large'))
            ->withQueryString();

        // Get filter options
        $users = \App\Models\User::forOrganization($request->user()->organization_id)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        $actions = ActivityLog::forOrganization($request->user()->organization_id)
            ->select('action')
            ->distinct()
            ->pluck('action');

        $subjectTypes = ActivityLog::forOrganization($request->user()->organization_id)
            ->select('subject_type')
            ->distinct()
            ->pluck('subject_type')
            ->map(function ($type) {
                return [
                    'value' => $type,
                    'label' => class_basename($type),
                ];
            });

        return Inertia::render('Admin/ActivityLog/Index', [
            'activities' => $activities,
            'filters' => $request->only(['user_id', 'action', 'subject_type', 'date_from', 'date_to', 'search']),
            'users' => $users,
            'actions' => $actions,
            'subjectTypes' => $subjectTypes,
        ]);
    }
}
