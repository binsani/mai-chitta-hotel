<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveTestimonialRequest;
use App\Models\Testimonial;
use App\Services\AuditLogger;
use App\Services\CmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class TestimonialController extends Controller
{
    public function __construct(
        private CmsService $cms,
        private AuditLogger $audit,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('website.view');

        return response()->json([
            'data' => Testimonial::orderBy('sort_order')->orderByDesc('id')->get(),
        ]);
    }

    public function store(SaveTestimonialRequest $request): JsonResponse
    {
        $item = $this->cms->saveTestimonial(null, $request->validated(), $request->user());

        return response()->json(['data' => $item], 201);
    }

    public function update(SaveTestimonialRequest $request, Testimonial $testimonial): JsonResponse
    {
        $item = $this->cms->saveTestimonial($testimonial, $request->validated(), $request->user());

        return response()->json(['data' => $item]);
    }

    public function destroy(Testimonial $testimonial): JsonResponse
    {
        Gate::authorize('website.delete');
        $this->audit->log('cms.testimonial.deleted', $testimonial);
        $testimonial->delete();
        CmsService::bumpPublicCache();

        return response()->json(null, 204);
    }
}
