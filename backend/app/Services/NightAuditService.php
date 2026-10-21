<?php

namespace App\Services;

use App\Models\GuestFolio;
use App\Models\NightAudit;
use App\Models\PosOrder;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NightAuditService
{
    public function __construct(
        protected AccountingService $accountingService,
        protected FolioService $folioService,
        protected AuditLogger $auditLogger
    ) {}

    /**
     * Run operational checklist before executing night audit.
     * Checks for:
     * - Pending arrivals not checked-in
     * - Pending departures not checked-out
     * - Open restaurant/bar POS orders
     * - Unbalanced folios or open charges
     */
    public function getPreAuditChecklist(string $auditDate): array
    {
        $date = Carbon::parse($auditDate);

        // Pending check-ins for the audit date
        $pendingArrivals = Reservation::whereDate('check_in_date', '<=', $date->toDateString())
            ->whereIn('status', ['confirmed', 'guaranteed'])
            ->with(['guest', 'roomType'])
            ->get();

        // Pending departures for the audit date
        $pendingDepartures = Reservation::whereDate('check_out_date', '<=', $date->toDateString())
            ->where('status', 'checked_in')
            ->with(['guest', 'room'])
            ->get();

        // Unclosed POS orders
        $openPosOrders = PosOrder::whereIn('status', ['draft', 'submitted', 'preparing', 'ready'])
            ->with(['outlet', 'table'])
            ->get();

        // Active checked-in reservations ready for room charge posting
        $inHouseReservations = Reservation::where('status', 'checked_in')
            ->whereDate('check_in_date', '<=', $date->toDateString())
            ->whereDate('check_out_date', '>', $date->toDateString())
            ->with(['guest', 'room', 'roomType', 'folio'])
            ->get();

        return [
            'audit_date' => $date->toDateString(),
            'pending_arrivals_count' => $pendingArrivals->count(),
            'pending_arrivals' => $pendingArrivals->map(fn ($r) => [
                'id' => $r->id,
                'reservation_number' => $r->reservation_number,
                'guest_name' => $r->guest?->full_name,
                'check_in_date' => $r->check_in_date->toDateString(),
            ])->values()->all(),
            'pending_departures_count' => $pendingDepartures->count(),
            'pending_departures' => $pendingDepartures->map(fn ($r) => [
                'id' => $r->id,
                'reservation_number' => $r->reservation_number,
                'guest_name' => $r->guest?->full_name,
                'room_number' => $r->room?->room_number,
            ])->values()->all(),
            'open_pos_orders_count' => $openPosOrders->count(),
            'open_pos_orders' => $openPosOrders->map(fn ($o) => [
                'id' => $o->id,
                'order_number' => $o->order_number,
                'outlet_name' => $o->outlet?->name,
                'total_minor' => $o->total_minor,
                'status' => $o->status,
            ])->values()->all(),
            'in_house_stays_count' => $inHouseReservations->count(),
            'can_proceed' => $openPosOrders->count() === 0, // POS orders must be closed or settled
        ];
    }

    /**
     * Execute the full Night Audit:
     * 1. Check idempotency (prevent running twice for the same date unless failed).
     * 2. Post room accommodation & tax charges to in-house guest folios.
     * 3. Compute room inventory statistics (total, occupied, vacant clean/dirty, out of order).
     * 4. Aggregate daily revenue by department (Room Revenue, F&B, Other) and payments.
     * 5. Post General Ledger Journal Entry for the day's revenue and ledger balances.
     * 6. Advance the system business date.
     */
    public function executeNightAudit(string $auditDate, ?User $actor = null, array $options = []): NightAudit
    {
        $date = Carbon::parse($auditDate);

        // Idempotency check: if already completed, fail
        $existing = NightAudit::whereDate('audit_date', $date->toDateString())->first();
        if ($existing && $existing->status === 'completed') {
            throw ValidationException::withMessages(['audit_date' => "Night audit for {$date->toDateString()} has already been successfully completed."]);
        }

        return DB::transaction(function () use ($date, $existing, $actor, $options) {
            $audit = $existing ?? NightAudit::create([
                'audit_date' => $date->toDateString(),
                'status' => 'in_progress',
                'auditor_user_id' => $actor?->id ?? User::first()->id,
                'started_at' => now(),
            ]);

            // 1. In-house reservations for accommodation posting
            $inHouse = Reservation::where('status', 'checked_in')
                ->whereDate('check_in_date', '<=', $date->toDateString())
                ->whereDate('check_out_date', '>', $date->toDateString())
                ->with(['folio', 'room', 'roomType'])
                ->get();

            $roomRevenueMinor = 0;
            $taxCollectedMinor = 0;

            foreach ($inHouse as $res) {
                if ($res->folio) {
                    // Sync room night charge idempotently
                    $totalAcc = (int) ($res->total_accommodation_minor ?? $res->subtotal_minor ?? $res->total_minor ?? 0);
                    $nights = max(1, (int) ($res->nights ?? $res->nights_count ?? 1));
                    $rateMinor = (int) round($totalAcc / $nights);
                    $taxMinor = (int) round($rateMinor * 0.075); // 7.5% VAT

                    $roomNumber = $res->assignedRoom?->room_number ?? 'N/A';
                    $this->folioService->postTrustedExternalCharge(
                        reservation: $res,
                        sourceType: 'night_audit',
                        sourceId: "{$res->id}-{$date->toDateString()}",
                        chargeData: [
                            'description' => "Room Night ({$date->toDateString()}) - Room {$roomNumber}",
                            'category' => 'accommodation',
                            'net_amount_minor' => $rateMinor,
                            'tax_amount_minor' => $taxMinor,
                            'service_charge_minor' => 0,
                            'idempotency_key' => "ROOM-NIGHT-{$res->id}-{$date->toDateString()}",
                        ],
                        actor: $actor
                    );

                    $roomRevenueMinor += $rateMinor;
                    $taxCollectedMinor += $taxMinor;
                }
            }

            // 2. Aggregate F&B and other revenue for the date
            $fnbRevenueMinor = (int) DB::table('pos_orders')
                ->whereDate('ordered_at', $date->toDateString())
                ->where('status', 'completed')
                ->sum('subtotal_minor');

            $fnbTaxMinor = (int) DB::table('pos_orders')
                ->whereDate('ordered_at', $date->toDateString())
                ->where('status', 'completed')
                ->sum('tax_minor');

            $taxCollectedMinor += $fnbTaxMinor;

            $totalRevenueMinor = $roomRevenueMinor + $fnbRevenueMinor;

            // 3. Aggregate payments received on this date
            $paymentsReceivedMinor = (int) DB::table('payment_transactions')
                ->whereDate('created_at', $date->toDateString())
                ->where('status', 'successful')
                ->sum('amount_minor');

            // 4. Room statistics
            $totalRooms = Room::count();
            $occupiedRooms = $inHouse->count();
            $outOfOrderRooms = Room::where('operational_status', 'out_of_order')->count();
            $vacantCleanRooms = Room::where('operational_status', 'available')->where('cleaning_status', 'clean')->count();
            $vacantDirtyRooms = Room::where('operational_status', 'available')->where('cleaning_status', 'dirty')->count();

            $occupancyPercentage = $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100, 2) : 0.0;
            $adrMinor = $occupiedRooms > 0 ? (int) round($roomRevenueMinor / $occupiedRooms) : 0;
            $revparMinor = $totalRooms > 0 ? (int) round($roomRevenueMinor / $totalRooms) : 0;

            // 5. Generate and post General Ledger Journal Entry
            $jeLines = [];

            // Debit Guest Ledger (AR) for room charges + taxes
            if (($roomRevenueMinor + $taxCollectedMinor) > 0) {
                $jeLines[] = [
                    'account_code' => '1110', // Guest Ledger / Accounts Receivable
                    'debit_minor' => $roomRevenueMinor + $taxCollectedMinor,
                    'credit_minor' => 0,
                    'department' => 'ROOMS',
                    'memo' => "Night audit guest ledger posting for {$date->toDateString()}",
                ];
            }

            // Credit Room Revenue
            if ($roomRevenueMinor > 0) {
                $jeLines[] = [
                    'account_code' => '4010', // Room Revenue
                    'debit_minor' => 0,
                    'credit_minor' => $roomRevenueMinor,
                    'department' => 'ROOMS',
                    'memo' => "Daily room revenue for {$date->toDateString()}",
                ];
            }

            // Credit VAT Payable
            if ($taxCollectedMinor > 0) {
                $jeLines[] = [
                    'account_code' => '2030', // VAT Payable
                    'debit_minor' => 0,
                    'credit_minor' => $taxCollectedMinor,
                    'department' => 'GA',
                    'memo' => "VAT collected for {$date->toDateString()}",
                ];
            }

            $journalEntry = null;
            if (! empty($jeLines)) {
                $journalEntry = $this->accountingService->createAndPostJournalEntry([
                    'entry_date' => $date->toDateString(),
                    'source_module' => 'NIGHT_AUDIT',
                    'source_reference' => "AUDIT:{$date->toDateString()}",
                    'description' => "Daily Night Audit Revenue & Tax Close for {$date->toDateString()}",
                    'lines' => $jeLines,
                ], $actor);
            }

            // 6. Update Night Audit record
            $audit->update([
                'status' => 'completed',
                'completed_at' => now(),
                'total_rooms' => $totalRooms,
                'occupied_rooms' => $occupiedRooms,
                'vacant_clean_rooms' => $vacantCleanRooms,
                'vacant_dirty_rooms' => $vacantDirtyRooms,
                'out_of_order_rooms' => $outOfOrderRooms,
                'occupancy_percentage' => $occupancyPercentage,
                'room_revenue_minor' => $roomRevenueMinor,
                'fnb_revenue_minor' => $fnbRevenueMinor,
                'other_revenue_minor' => 0,
                'total_revenue_minor' => $totalRevenueMinor,
                'tax_collected_minor' => $taxCollectedMinor,
                'payments_received_minor' => $paymentsReceivedMinor,
                'adr_minor' => $adrMinor,
                'revpar_minor' => $revparMinor,
                'journal_entry_id' => $journalEntry?->id,
                'operational_checklist' => [
                    'in_house_processed' => $occupiedRooms,
                    'open_pos_orders' => 0,
                ],
                'notes' => $options['notes'] ?? 'Daily close executed successfully.',
            ]);

            // Advance the hotel current business date in settings
            $nextDate = $date->copy()->addDay()->toDateString();
            Setting::updateOrCreate(
                ['key' => 'hotel.business_date'],
                ['value' => $nextDate, 'group' => 'hotel']
            );

            $this->auditLogger->log(
                action: 'night_audit.completed',
                subject: $audit,
                context: [
                    'audit_date' => $audit->audit_date->toDateString(),
                    'next_business_date' => $nextDate,
                    'total_revenue_minor' => $audit->total_revenue_minor,
                    'occupancy_percentage' => $audit->occupancy_percentage,
                    'adr_minor' => $audit->adr_minor,
                    'revpar_minor' => $audit->revpar_minor,
                ],
                userId: $actor?->id
            );

            return $audit->load(['auditor', 'journalEntry.lines.account']);
        });
    }

    /**
     * Get Analytics summary for KPI dashboard.
     */
    public function getAnalyticsSummary(string $startDate, string $endDate): array
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        $audits = NightAudit::where('status', 'completed')
            ->whereBetween('audit_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('audit_date')
            ->get();

        $totalRoomRevenue = (int) $audits->sum('room_revenue_minor');
        $totalFnbRevenue = (int) $audits->sum('fnb_revenue_minor');
        $totalRevenue = (int) $audits->sum('total_revenue_minor');
        $avgOccupancy = $audits->count() > 0 ? round($audits->avg('occupancy_percentage'), 2) : 0.0;
        $avgAdr = $audits->count() > 0 ? (int) round($audits->avg('adr_minor')) : 0;
        $avgRevpar = $audits->count() > 0 ? (int) round($audits->avg('revpar_minor')) : 0;

        return [
            'period' => [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'days_count' => $audits->count(),
            ],
            'kpis' => [
                'average_occupancy_percentage' => $avgOccupancy,
                'average_adr_minor' => $avgAdr,
                'average_revpar_minor' => $avgRevpar,
                'total_room_revenue_minor' => $totalRoomRevenue,
                'total_fnb_revenue_minor' => $totalFnbRevenue,
                'total_revenue_minor' => $totalRevenue,
            ],
            'daily_trend' => $audits->map(fn ($a) => [
                'date' => $a->audit_date->toDateString(),
                'occupancy_percentage' => (float) $a->occupancy_percentage,
                'adr_minor' => $a->adr_minor,
                'revpar_minor' => $a->revpar_minor,
                'revenue_minor' => $a->total_revenue_minor,
            ])->values()->all(),
        ];
    }
}
