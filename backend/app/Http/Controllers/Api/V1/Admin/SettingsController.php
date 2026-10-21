<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SettingsController extends Controller
{
    public function __construct(private SettingsService $settings, private AuditLogger $audit) {}

    public function show(Request $request): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['settings.view', 'reservations.settings', 'reservations.view'])) {
            abort(403, 'You do not have permission to view settings.');
        }

        return response()->json(['data' => $this->settings->all(), 'integrations' => $this->settings->integrationStatus()]);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $payload = $request->validated();
        if (! $request->user()->can('settings.edit')) {
            // Users with only reservations.settings may only update the 'booking' and 'operations' groups
            $payload = array_intersect_key($payload, ['booking' => true, 'operations' => true]);
        }

        $changes = $this->settings->update($payload, $request->user());
        if ($changes) {
            $this->audit->log('settings.updated', null, ['changes' => $changes]);
        }

        return response()->json(['data' => $this->settings->all(), 'changed' => array_keys($changes)]);
    }

    /** Public, non-sensitive settings for the public website. */
    public function public(): JsonResponse
    {
        return response()->json(['data' => $this->settings->all(publicOnly: true)]);
    }

    /** Real system facts only — no fabricated business metrics. */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = [
            'hotel' => $this->settings->all()['hotel'],
            'server_time' => now()->toIso8601String(),
            'environment' => app()->environment(),
            'versions' => ['laravel' => app()->version(), 'php' => PHP_VERSION],
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ];
        if ($user->can('users.view')) {
            $data['counts'] = ['users' => User::count(), 'active_users' => User::where('is_active', true)->count(), 'roles' => Role::count()];
        }
        if ($user->can('website.view')) {
            $data['cms_counts'] = [
                'published_pages' => \App\Models\Page::where('status', 'published')->count(),
                'draft_pages' => \App\Models\Page::where('status', 'draft')->count(),
                'media_assets' => \App\Models\MediaAsset::count(),
                'published_rooms' => \App\Models\RoomContent::where('status', 'published')->count(),
                'published_offers' => \App\Models\Offer::where('status', 'published')->count(),
                'new_inquiries' => \App\Models\ContactInquiry::where('status', 'new')->count(),
            ];
        }
        if ($user->can('audit.view')) {
            $data['recent_failed_logins'] = AuditLog::where('action', 'auth.login')->where('outcome', 'failure')
                ->where('created_at', '>=', now()->subDay())->count();
        }
        if ($user->can('settings.view') || $user->can('reservations.settings')) {
            $data['integrations'] = $this->settings->integrationStatus();
        }
        if ($user->hasAnyPermission(['reservations.view', 'frontdesk.view', 'rooms.view'])) {
            $today = now()->format('Y-m-d');
            $data['reservation_counts'] = [
                'room_types' => \App\Models\RoomType::where('is_active', true)->count(),
                'physical_rooms' => \App\Models\Room::where('operational_status', 'active')->count(),
                'confirmed_reservations' => \App\Models\Reservation::where('status', 'confirmed')->count(),
                'checked_in_reservations' => \App\Models\Reservation::where('status', 'checked_in')->count(),
                'today_arrivals' => \App\Models\Reservation::whereDate('check_in_date', $today)->whereIn('status', ['confirmed', 'pending_payment'])->count(),
                'today_departures' => \App\Models\Reservation::whereDate('check_out_date', $today)->where('status', 'checked_in')->count(),
            ];
        }
        if ($user->hasAnyPermission(['housekeeping.view', 'maintenance.view', 'folios.view'])) {
            $data['operations_counts'] = [
                'housekeeping_open' => \App\Models\HousekeepingTask::whereIn('status', \App\Models\HousekeepingTask::ACTIVE_STATUSES)->count(),
                'rooms_dirty' => \App\Models\Room::where('housekeeping_status', 'dirty')->count(),
                'rooms_awaiting_inspection' => \App\Models\Room::where('housekeeping_status', 'inspecting')->count(),
                'maintenance_open' => \App\Models\MaintenanceWorkOrder::whereIn('status', \App\Models\MaintenanceWorkOrder::OPEN_STATUSES)->count(),
                'open_folios' => \App\Models\GuestFolio::where('status', 'open')->count(),
                'pending_folio_adjustments' => \App\Models\FolioEntry::where('status', 'pending_approval')->count(),
            ];
        }
        if ($user->hasAnyPermission(['pos.view', 'inventory.view', 'procurement.view'])) {
            $data['phase5_counts'] = [
                'pos_outlets' => \App\Models\PosOutlet::where('is_active', true)->count(),
                'active_pos_orders' => \App\Models\PosOrder::whereIn('status', ['submitted', 'preparing', 'ready', 'out_for_delivery'])->count(),
                'low_stock_items' => \App\Models\InventoryItem::where('is_active', true)->where('reorder_level', '>', 0)->whereColumn('current_stock', '<=', 'reorder_level')->count(),
                'pending_requisitions' => \App\Models\PurchaseRequisition::where('status', 'pending_approval')->count(),
                'pending_purchase_orders' => \App\Models\PurchaseOrder::where('status', 'pending_approval')->count(),
            ];
        }

        return response()->json(['data' => $data]);
    }
}
