<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveOfferRequest;
use App\Models\Offer;
use App\Services\AuditLogger;
use App\Services\CmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class OfferController extends Controller
{
    public function __construct(
        private CmsService $cms,
        private AuditLogger $audit,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('website.view');

        return response()->json([
            'data' => Offer::with('featuredMedia')->orderBy('sort_order')->orderByDesc('id')->get(),
        ]);
    }

    public function show(Offer $offer): JsonResponse
    {
        Gate::authorize('website.view');

        return response()->json(['data' => $offer->load('featuredMedia')]);
    }

    public function store(SaveOfferRequest $request): JsonResponse
    {
        $offer = $this->cms->saveOffer(null, $request->validated(), $request->user());

        return response()->json(['data' => $offer], 201);
    }

    public function update(SaveOfferRequest $request, Offer $offer): JsonResponse
    {
        $updated = $this->cms->saveOffer($offer, $request->validated(), $request->user());

        return response()->json(['data' => $updated]);
    }

    public function destroy(Offer $offer): JsonResponse
    {
        Gate::authorize('website.delete');
        $this->audit->log('cms.offer.deleted', $offer, ['slug' => $offer->slug]);
        $offer->delete();
        CmsService::bumpPublicCache();

        return response()->json(null, 204);
    }
}
