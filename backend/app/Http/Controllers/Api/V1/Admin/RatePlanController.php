<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\RatePlan;
use App\Models\RatePlanOverride;
use App\Models\RatePlanRoomType;
use App\Services\AuditLogger;
use App\Services\AvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RatePlanController extends Controller
{
    public function __construct(
        private AuditLogger $audit,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('rooms.view');

        $plans = RatePlan::query()
            ->with(['roomTypeRates.roomType', 'overrides.roomType'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $plans]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('rooms.rates');

        $data = $this->validateRatePlan($request);

        $plan = DB::transaction(function () use ($data, $request) {
            $rp = RatePlan::create([
                ...$data['plan'],
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);

            $this->syncRoomTypeRates($rp, $data['room_type_rates']);

            return $rp;
        });

        $this->audit->log('rate_plan.created', $plan, [
            'name' => $plan->name,
            'code' => $plan->code,
            'default_base_rate_minor' => $plan->default_base_rate_minor,
        ]);

        return response()->json([
            'message' => 'Rate plan created.',
            'data' => $plan->load(['roomTypeRates.roomType', 'overrides.roomType']),
        ], 201);
    }

    public function show(RatePlan $ratePlan): JsonResponse
    {
        Gate::authorize('rooms.view');

        return response()->json([
            'data' => $ratePlan->load(['roomTypeRates.roomType', 'overrides.roomType']),
        ]);
    }

    public function update(Request $request, RatePlan $ratePlan): JsonResponse
    {
        Gate::authorize('rooms.rates');

        $data = $this->validateRatePlan($request, $ratePlan->id);
        $oldBaseMinor = $ratePlan->default_base_rate_minor;

        DB::transaction(function () use ($ratePlan, $data, $request) {
            $ratePlan->update([
                ...$data['plan'],
                'updated_by' => $request->user()->id,
            ]);

            if (array_key_exists('room_type_rates', $data)) {
                $this->syncRoomTypeRates($ratePlan, $data['room_type_rates']);
            }
        });

        $this->audit->log('rate_plan.updated', $ratePlan, [
            'name' => $ratePlan->name,
            'code' => $ratePlan->code,
            'old_base_rate_minor' => $oldBaseMinor,
            'new_base_rate_minor' => $ratePlan->default_base_rate_minor,
        ]);

        return response()->json([
            'message' => 'Rate plan updated.',
            'data' => $ratePlan->fresh(['roomTypeRates.roomType', 'overrides.roomType']),
        ]);
    }

    public function destroy(RatePlan $ratePlan): JsonResponse
    {
        Gate::authorize('rooms.rates');

        if (\App\Models\Reservation::where('rate_plan_id', $ratePlan->id)->exists()) {
            return response()->json([
                'message' => 'Rate plan has reservation history and cannot be deleted. Mark it inactive instead.',
            ], 422);
        }

        $this->audit->log('rate_plan.deleted', $ratePlan, ['code' => $ratePlan->code]);
        $ratePlan->delete();

        return response()->json(['message' => 'Rate plan deleted.']);
    }

    public function storeOverride(Request $request, RatePlan $ratePlan): JsonResponse
    {
        Gate::authorize('rooms.rates');

        $v = $request->validate([
            'room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'label' => ['nullable', 'string', 'max:120'],
            'override_type' => ['required', 'string', Rule::in(['exact_date', 'date_range'])],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'nightly_rate' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'extra_adult_rate' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'extra_child_rate' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'min_stay_nights' => ['nullable', 'integer', 'min:1', 'max:365'],
            'max_stay_nights' => ['nullable', 'integer', 'min:1', 'max:365'],
            'closed_to_sell' => ['boolean'],
            'closed_to_arrival' => ['boolean'],
            'closed_to_departure' => ['boolean'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $override = RatePlanOverride::create([
            'rate_plan_id' => $ratePlan->id,
            'room_type_id' => (int) $v['room_type_id'],
            'label' => isset($v['label']) ? strip_tags($v['label']) : null,
            'override_type' => $v['override_type'],
            'start_date' => $v['start_date'],
            'end_date' => $v['override_type'] === 'exact_date' ? $v['start_date'] : $v['end_date'],
            'nightly_rate' => isset($v['nightly_rate']) ? round((float) $v['nightly_rate'], 2) : null,
            'nightly_rate_minor' => isset($v['nightly_rate']) ? AvailabilityService::toMinor($v['nightly_rate']) : null,
            'extra_adult_rate' => isset($v['extra_adult_rate']) ? round((float) $v['extra_adult_rate'], 2) : null,
            'extra_adult_rate_minor' => isset($v['extra_adult_rate']) ? AvailabilityService::toMinor($v['extra_adult_rate']) : null,
            'extra_child_rate' => isset($v['extra_child_rate']) ? round((float) $v['extra_child_rate'], 2) : null,
            'extra_child_rate_minor' => isset($v['extra_child_rate']) ? AvailabilityService::toMinor($v['extra_child_rate']) : null,
            'min_stay_nights' => $v['min_stay_nights'] ?? null,
            'max_stay_nights' => $v['max_stay_nights'] ?? null,
            'closed_to_sell' => (bool) ($v['closed_to_sell'] ?? false),
            'closed_to_arrival' => (bool) ($v['closed_to_arrival'] ?? false),
            'closed_to_departure' => (bool) ($v['closed_to_departure'] ?? false),
            'priority' => (int) ($v['priority'] ?? 10),
            'created_by' => $request->user()->id,
        ]);

        $this->audit->log('rate_plan.override_created', $override, [
            'rate_plan_id' => $ratePlan->id,
            'room_type_id' => $override->room_type_id,
            'start_date' => $override->start_date->format('Y-m-d'),
            'end_date' => $override->end_date->format('Y-m-d'),
            'nightly_rate_minor' => $override->nightly_rate_minor,
        ]);

        return response()->json([
            'message' => 'Date-specific rate override saved.',
            'data' => $override->load('roomType'),
        ], 201);
    }

    public function destroyOverride(RatePlan $ratePlan, RatePlanOverride $override): JsonResponse
    {
        Gate::authorize('rooms.rates');
        abort_if($override->rate_plan_id !== $ratePlan->id, 404);

        $this->audit->log('rate_plan.override_deleted', $override, ['rate_plan_id' => $ratePlan->id]);
        $override->delete();

        return response()->json(['message' => 'Rate override deleted.']);
    }

    private function validateRatePlan(Request $request, ?int $ignoreId = null): array
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('rate_plans', 'code')->ignore($ignoreId)],
            'description' => ['nullable', 'string', 'max:2000'],
            'meal_basis' => ['nullable', 'string', Rule::in(['room_only', 'breakfast_included', 'half_board', 'full_board'])],
            'cancellation_policy_type' => ['nullable', 'string', Rule::in(['unconfigured', 'flexible', 'moderate', 'non_refundable', 'custom'])],
            'free_cancellation_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
            'cancellation_penalty_type' => ['nullable', 'string', Rule::in(['none', 'first_night', 'percentage', 'full_stay'])],
            'cancellation_penalty_value' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cancellation_terms' => ['nullable', 'string', 'max:3000'],
            'currency_code' => ['nullable', 'string', 'max:8'],
            'default_base_rate' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'extra_adult_rate' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'extra_child_rate' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'effective_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'min_stay_nights' => ['nullable', 'integer', 'min:1', 'max:365'],
            'max_stay_nights' => ['nullable', 'integer', 'min:1', 'max:365'],
            'closed_to_arrival' => ['boolean'],
            'closed_to_departure' => ['boolean'],
            'is_active' => ['boolean'],
            'is_public' => ['boolean'],
            'is_refundable' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'room_type_rates' => ['nullable', 'array'],
            'room_type_rates.*.room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'room_type_rates.*.base_rate' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'room_type_rates.*.extra_adult_rate' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'room_type_rates.*.extra_child_rate' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'room_type_rates.*.weekday_rates' => ['nullable', 'array'],
            'room_type_rates.*.is_active' => ['boolean'],
        ]);

        $baseRate = round((float) $v['default_base_rate'], 2);
        $extraAdult = round((float) ($v['extra_adult_rate'] ?? 0), 2);
        $extraChild = round((float) ($v['extra_child_rate'] ?? 0), 2);

        return [
            'plan' => [
                'name' => strip_tags($v['name']),
                'code' => strtoupper(strip_tags($v['code'])),
                'description' => isset($v['description']) ? strip_tags($v['description']) : null,
                'meal_Basis' => $v['meal_basis'] ?? 'room_only',
                'cancellation_policy_type' => $v['cancellation_policy_type'] ?? 'unconfigured',
                'free_cancellation_hours' => $v['free_cancellation_hours'] ?? null,
                'cancellation_penalty_type' => $v['cancellation_penalty_type'] ?? 'none',
                'cancellation_penalty_value' => (float) ($v['cancellation_penalty_value'] ?? 0),
                'cancellation_terms' => isset($v['cancellation_terms']) ? strip_tags($v['cancellation_terms']) : null,
                'currency_code' => strtoupper($v['currency_code'] ?? 'NGN'),
                'default_base_rate' => $baseRate,
                'default_base_rate_minor' => AvailabilityService::toMinor($baseRate),
                'extra_adult_rate' => $extraAdult,
                'extra_adult_rate_minor' => AvailabilityService::toMinor($extraAdult),
                'extra_child_rate' => $extraChild,
                'extra_child_rate_minor' => AvailabilityService::toMinor($extraChild),
                'effective_from' => $v['effective_from'] ?? null,
                'effective_until' => $v['effective_until'] ?? null,
                'min_stay_nights' => (int) ($v['min_stay_nights'] ?? 1),
                'max_stay_nights' => isset($v['max_stay_nights']) ? (int) $v['max_stay_nights'] : null,
                'closed_to_arrival' => (bool) ($v['closed_to_arrival'] ?? false),
                'closed_to_departure' => (bool) ($v['closed_to_departure'] ?? false),
                'is_active' => (bool) ($v['is_active'] ?? true),
                'is_public' => (bool) ($v['is_public'] ?? true),
                'is_refundable' => (bool) ($v['is_refundable'] ?? true),
                'sort_order' => (int) ($v['sort_order'] ?? 0),
            ],
            'room_type_rates' => $v['room_type_rates'] ?? [],
        ];
    }

    private function syncRoomTypeRates(RatePlan $ratePlan, array $items): void
    {
        $keepIds = [];
        foreach ($items as $item) {
            $base = round((float) $item['base_rate'], 2);
            $extraAdult = round((float) ($item['extra_adult_rate'] ?? $ratePlan->extra_adult_rate), 2);
            $extraChild = round((float) ($item['extra_child_rate'] ?? $ratePlan->extra_child_rate), 2);

            $weekdayMajor = [];
            $weekdayMinor = [];
            foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $d) {
                if (isset($item['weekday_rates'][$d]) && is_numeric($item['weekday_rates'][$d]) && (float) $item['weekday_rates'][$d] > 0) {
                    $val = round((float) $item['weekday_rates'][$d], 2);
                    $weekdayMajor[$d] = $val;
                    $weekdayMinor[$d] = AvailabilityService::toMinor($val);
                }
            }

            $pivot = RatePlanRoomType::updateOrCreate(
                ['rate_plan_id' => $ratePlan->id, 'room_type_id' => (int) $item['room_type_id']],
                [
                    'base_rate' => $base,
                    'base_rate_minor' => AvailabilityService::toMinor($base),
                    'extra_adult_rate' => $extraAdult,
                    'extra_adult_rate_minor' => AvailabilityService::toMinor($extraAdult),
                    'extra_child_rate' => $extraChild,
                    'extra_child_rate_minor' => AvailabilityService::toMinor($extraChild),
                    'weekday_rates' => $weekdayMajor ?: null,
                    'weekday_rates_minor' => $weekdayMinor ?: null,
                    'is_active' => (bool) ($item['is_active'] ?? true),
                ]
            );
            $keepIds[] = $pivot->id;
        }

        if (! empty($items)) {
            RatePlanRoomType::where('rate_plan_id', $ratePlan->id)
                ->whereNotIn('id', $keepIds)
                ->delete();
        }
    }
}
