<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveRoomContentRequest;
use App\Models\RoomContent;
use App\Services\AuditLogger;
use App\Services\CmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class RoomContentController extends Controller
{
    public function __construct(
        private CmsService $cms,
        private AuditLogger $audit,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('website.view');

        return response()->json([
            'data' => RoomContent::with('featuredMedia')->orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    public function show(RoomContent $roomContent): JsonResponse
    {
        Gate::authorize('website.view');

        return response()->json(['data' => $roomContent->load('featuredMedia')]);
    }

    public function store(SaveRoomContentRequest $request): JsonResponse
    {
        $room = $this->cms->saveRoomContent(null, $request->validated(), $request->user());

        return response()->json(['data' => $room], 201);
    }

    public function update(SaveRoomContentRequest $request, RoomContent $roomContent): JsonResponse
    {
        $room = $this->cms->saveRoomContent($roomContent, $request->validated(), $request->user());

        return response()->json(['data' => $room]);
    }

    public function destroy(RoomContent $roomContent): JsonResponse
    {
        Gate::authorize('website.delete');
        $this->audit->log('cms.room_content.deleted', $roomContent, ['slug' => $roomContent->slug]);
        $roomContent->delete();
        CmsService::bumpPublicCache();

        return response()->json(null, 204);
    }
}
