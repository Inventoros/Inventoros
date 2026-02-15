<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing user notifications.
 *
 * Handles displaying, marking as read, and deleting
 * user notifications within an organization.
 */
class NotificationController extends Controller
{
    /**
     * Display a listing of notifications.
     *
     * @param Request $request The incoming HTTP request
     * @return Response
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $filter = $request->query('filter', 'all'); // all, unread, read

        $query = Notification::forUser($user->id)
            ->forOrganization($user->organization_id)
            ->with('user')
            ->orderBy('created_at', 'desc');

        if ($filter === 'unread') {
            $query->unread();
        } elseif ($filter === 'read') {
            $query->read();
        }

        $notifications = $query->paginate(20)->withQueryString();

        $stats = [
            'total' => Notification::forUser($user->id)->forOrganization($user->organization_id)->count(),
            'unread' => Notification::forUser($user->id)->forOrganization($user->organization_id)->unread()->count(),
            'read' => Notification::forUser($user->id)->forOrganization($user->organization_id)->read()->count(),
        ];

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
            'stats' => $stats,
            'currentFilter' => $filter,
        ]);
    }

    /**
     * Get unread notification count.
     *
     * @param Request $request The incoming HTTP request
     * @return \Illuminate\Http\JsonResponse
     */
    public function unreadCount(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();

        $count = Notification::forUser($user->id)
            ->forOrganization($user->organization_id)
            ->unread()
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Mark a notification as read.
     *
     * @param Request $request The incoming HTTP request
     * @param Notification $notification The notification to mark as read
     * @return RedirectResponse
     */
    public function markAsRead(Request $request, Notification $notification): RedirectResponse
    {
        $user = $request->user();

        // Ensure the notification belongs to the user
        if ($notification->user_id !== $user->id) {
            abort(403, 'Unauthorized access to notification');
        }

        $notification->markAsRead();

        // Redirect to action URL if provided
        if ($notification->action_url) {
            return redirect($notification->action_url);
        }

        return redirect()->back();
    }

    /**
     * Mark all notifications as read.
     *
     * @param Request $request The incoming HTTP request
     * @return RedirectResponse
     */
    public function markAllAsRead(Request $request): RedirectResponse
    {
        $user = $request->user();

        Notification::forUser($user->id)
            ->forOrganization($user->organization_id)
            ->unread()
            ->update(['read_at' => now()]);

        return redirect()->back()->with('success', 'All notifications marked as read');
    }

    /**
     * Delete a notification.
     *
     * @param Request $request The incoming HTTP request
     * @param Notification $notification The notification to delete
     * @return RedirectResponse
     */
    public function destroy(Request $request, Notification $notification): RedirectResponse
    {
        $user = $request->user();

        // Ensure the notification belongs to the user
        if ($notification->user_id !== $user->id) {
            abort(403, 'Unauthorized access to notification');
        }

        $notification->delete();

        return redirect()->back()->with('success', 'Notification deleted');
    }

    /**
     * Delete all read notifications.
     *
     * @param Request $request The incoming HTTP request
     * @return RedirectResponse
     */
    public function clearRead(Request $request): RedirectResponse
    {
        $user = $request->user();

        Notification::forUser($user->id)
            ->forOrganization($user->organization_id)
            ->read()
            ->delete();

        return redirect()->back()->with('success', 'Read notifications cleared');
    }
}
