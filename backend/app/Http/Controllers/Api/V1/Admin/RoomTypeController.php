<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\RoomContent;
use App\Models\RoomType;
use App\Services\AuditLogger;
use App\Services\AvailabilityService;
use App\Services\CmsService;
use App\Support\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoomTypeController extends Controller
{
    public function __construct(
        private AuditLogger $audit,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('rooms.view');

        $roomTypes = RoomType::query()
            ->with(['featuredMedia'])
            ->withCount([
                'rooms',
                'rooms as active_rooms_count' => fn ($q) => $q->where('operational_status', 'active'),
                'rooms as out_of_service_rooms_count' => fn ($q) => $q->where('operational_status', 'out_of_service'),
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->each(fn (RoomType $rt) => $rt->makeVisible(['housekeeping_notes', 'operational_notes']));

        return response()->json(['data' => $roomTypes]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('rooms.create');

        $data = $this->validatePayload($request);

        $roomType = DB::transaction(function () use ($data, $request) {
            $rt = RoomType::create([
                ...$data,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);
            $this->syncRoomContent($rt, $request->user()->id);

            return $rt;
        });

        $this->audit->log('room_type.created', $roomType, ['name' => $roomType->name, 'slug' => $roomType->slug]);

        return response()->json([
            'message' => 'Room type created.',
            'data' => $roomType->load('featuredMedia')->makeVisible(['housekeeping_notes', 'operational_notes']),
        ], 201);
    }

    public function show(RoomType $roomType): JsonResponse
    {
        Gate::authorize('rooms.view');

        return response()->json([
            'data' => $roomType->load(['featuredMedia', 'rooms', 'ratePlans'])
                ->makeVisible(['housekeeping_notes', 'operational_notes']),
        ]);
    }

    public function update(Request $request, RoomType $roomType): JsonResponse
    {
        Gate::authorize('rooms.edit');

        $data = $this->validatePayload($request, $roomType->id);

        // Prevent deactivating a room type or reducing capacity if confirmed/checked-in stays exist
        if (array_key_exists('is_active', $data) && ! $data['is_active'] && $roomType->is_active) {
            $activeStays = Reservation::where('room_type_id', $roomType->id)
                ->whereIn('status', Reservation::INVENTORY_CONSUMING_STATUSES)
                ->where('check_out_date', '>', now()->format('Y-m-d'))
                ->count();

            if ($activeStays > 0) {
                throw ValidationException::withMessages([
                    'is_active' => ["Cannot deactivate {$roomType->name} while {$activeStays} active/upcoming reservation(s) exist."],
                ]);
            }
        }

        DB::transaction(function () use ($roomType, $data, $request) {
            $roomType->update([
                ...$data,
                'updated_by' => $request->user()->id,
            ]);
            $this->syncRoomContent($roomType, $request->user()->id);
        });

        $this->audit->log('room_type.updated', $roomType, ['name' => $roomType->name, 'slug' => $roomType->slug]);

        return response()->json([
            'message' => 'Room type updated.',
            'data' => $roomType->fresh('featuredMedia')->makeVisible(['housekeeping_notes', 'operational_notes']),
        ]);
    }

    public function destroy(RoomType $roomType): JsonResponse
    {
        Gate::authorize('rooms.edit');

        if ($roomType->rooms()->exists() || $roomType->reservations()->exists()) {
            throw ValidationException::withMessages([
                'room_type' => ['Cannot delete a room type that has physical rooms or reservation history. Mark it inactive instead.'],
            ]);
        }

        $this->audit->log('room_type.deleted', $roomType, ['name' => $roomType->name]);
        $roomType->delete();
        CmsService::bumpPublicCache();

        return response()->json(['message' => 'Room type deleted.']);
    }

    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/', Rule::unique('room_types', 'slug')->ignore($ignoreId)],
            'code' => ['nullable', 'string', 'max:24', Rule::unique('room_types', 'code')->ignore($ignoreId)],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
            'public_description' => ['nullable', 'string', 'max:15000'],
            'max_adults' => ['required', 'integer', 'min:1', 'max:20'],
            'max_children' => ['required', 'integer', 'min:0', 'max:20'],
            'max_occupancy' => ['required', 'integer', 'min:1', 'max:30'],
            'base_occupancy' => ['required', 'integer', 'min:1', 'max:20'],
            'bed_configuration' => ['nullable', 'string', 'max:160'],
            'size_label' => ['nullable', 'string', 'max:64'],
            'amenities' => ['nullable', 'array', 'max:50'],
            'amenities.*' => ['string', 'max:120'],
            'featured_media_id' => ['nullable', 'integer', 'exists:media_assets,id'],
            'gallery_media_ids' => ['nullable', 'array', 'max:24'],
            'gallery_media_ids.*' => ['integer', 'exists:media_assets,id'],
            'starting_rate' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'currency_code' => ['nullable', 'string', 'max:8'],
            'is_active' => ['boolean'],
            'is_public' => ['boolean'],
            'is_featured' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'housekeeping_notes' => ['nullable', 'string', 'max:2000'],
            'operational_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $startingRate = array_key_exists('starting_rate', $v) && $v['starting_rate'] !== null
            ? round((float) $v['starting_rate'], 2)
            : null;

        return [
            'name' => strip_tags($v['name']),
            'slug' => $v['slug'],
            'code' => isset($v['code']) && filled($v['code']) ? strtoupper(strip_tags($v['code'])) : null,
            'subtitle' => isset($v['subtitle']) ? strip_tags($v['subtitle']) : null,
            'description' => isset($v['description']) ? strip_tags($v['description']) : null,
            'public_description' => HtmlSanitizer::clean($v['public_description'] ?? null),
            'max_adults' => (int) $v['max_adults'],
            'max_children' => (int) $v['max_children'],
            'max_occupancy' => max((int) $v['max_adults'], (int) $v['max_occupancy']),
            'base_occupancy' => min((int) $v['max_occupancy'], (int) $v['base_occupancy']),
            'bed_configuration' => isset($v['bed_configuration']) ? strip_tags($v['bed_configuration']) : null,
            'size_label' => isset($v['size_label']) ? strip_tags($v['size_label']) : null,
            'amenities' => array_values(array_map('strip_tags', $v['amenities'] ?? [])),
            'featured_media_id' => $v['featured_media_id'] ?? null,
            'gallery_media_ids' => $v['gallery_media_ids'] ?? [],
            'starting_rate' => $startingRate,
            'starting_rate_minor' => $startingRate !== null ? AvailabilityService::toMinor($startingRate) : null,
            'currency_code' => strtoupper($v['currency_code'] ?? 'NGN'),
            'is_active' => (bool) ($v['is_active'] ?? true),
            'is_public' => (bool) ($v['is_public'] ?? true),
            'is_featured' => (bool) ($v['is_featured'] ?? false),
            'sort_order' => (int) ($v['sort_order'] ?? 0),
            'housekeeping_notes' => isset($v['housekeeping_notes']) ? strip_tags($v['housekeeping_notes']) : null,
            'operational_notes' => isset($v['operational_notes']) ? strip_tags($v['operational_notes']) : null,
        ];
    }

    private function syncRoomContent(RoomType $rt, int $actorId): void
    {
        $status = ($rt->is_active && $rt->is_public) ? 'published' : 'draft';
        $occupancyLabel = "{$rt->max_adults} Adult(s)" . ($rt->max_children > 0 ? ", {$rt->max_children} Child(ren)" : '');

        $rc = RoomContent::updateOrCreate(
            ['slug' => $rt->slug],
            [
                'title' => $rt->name,
                'subtitle' => $rt->subtitle,
                'summary' => $rt->description,
                'description' => $rt->public_description ?: $rt->description,
                'occupancy_label' => $occupancyLabel,
                'bed_configuration' => $rt->bed_configuration,
                'indicative_rate' => $rt->starting_rate,
                'amenities' => $rt->amenities ?? [],
                'featured_media_id' => $rt->featured_media_id,
                'status' => $status,
                'is_featured' => $rt->is_featured,
                'sort_order' => $rt->sort_order,
                'published_at' => $status === 'published' ? now() : null,
                'updated_by' => $actorId,
            ]
        );

        if ($rt->room_content_id !== $rc->id) {
            $rt->updateQuietly(['room_content_id' => $rc->id]);
        }

        CmsService::bumpPublicCache();
    }
}
