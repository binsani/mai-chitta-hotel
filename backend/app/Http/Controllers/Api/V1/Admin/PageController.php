<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SavePageRequest;
use App\Models\Page;
use App\Models\PageRevision;
use App\Services\AuditLogger;
use App\Services\CmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PageController extends Controller
{
    public function __construct(
        private CmsService $cms,
        private AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Page::class);
        $q = trim((string) $request->query('search', ''));
        $status = $request->query('status');

        $pages = Page::with(['featuredMedia', 'author:id,name'])
            ->withCount(['sections', 'revisions'])
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('title', 'like', "%{$q}%")->orWhere('slug', 'like', "%{$q}%")))
            ->when(is_string($status) && in_array($status, ['draft', 'published', 'archived'], true), fn ($b) => $b->where('status', $status))
            ->orderByDesc('is_system')
            ->orderBy('title')
            ->paginate(min(100, max(5, (int) $request->query('per_page', 25))));

        return response()->json($pages);
    }

    public function show(Page $page): JsonResponse
    {
        Gate::authorize('view', $page);

        return response()->json([
            'data' => $page->load(['sections.media', 'featuredMedia', 'ogImage', 'revisions.author:id,name']),
        ]);
    }

    public function store(SavePageRequest $request): JsonResponse
    {
        $page = $this->cms->createPage($request->validated(), $request->user());

        return response()->json(['data' => $page], 201);
    }

    public function update(SavePageRequest $request, Page $page): JsonResponse
    {
        $updated = $this->cms->updatePage($page, $request->validated(), $request->user());

        return response()->json(['data' => $updated]);
    }

    public function publish(Request $request, Page $page): JsonResponse
    {
        Gate::authorize('publish', $page);
        $updated = $this->cms->setPageStatus($page, 'published', $request->user(), $request->input('note', 'Published'));

        return response()->json(['data' => $updated]);
    }

    public function unpublish(Request $request, Page $page): JsonResponse
    {
        Gate::authorize('publish', $page);
        $updated = $this->cms->setPageStatus($page, 'draft', $request->user(), $request->input('note', 'Unpublished to draft'));

        return response()->json(['data' => $updated]);
    }

    public function archive(Request $request, Page $page): JsonResponse
    {
        Gate::authorize('publish', $page);
        $updated = $this->cms->setPageStatus($page, 'archived', $request->user(), $request->input('note', 'Archived'));

        return response()->json(['data' => $updated]);
    }

    public function revisions(Page $page): JsonResponse
    {
        Gate::authorize('view', $page);

        return response()->json([
            'data' => $page->revisions()->with('author:id,name')->get(),
        ]);
    }

    public function restoreRevision(Request $request, Page $page, PageRevision $revision): JsonResponse
    {
        Gate::authorize('update', $page);
        $updated = $this->cms->restoreRevision($page, $revision, $request->user());

        return response()->json(['data' => $updated]);
    }

    public function destroy(Page $page): JsonResponse
    {
        Gate::authorize('delete', $page);
        $this->audit->log('cms.page.deleted', $page, ['slug' => $page->slug]);
        $page->delete();
        CmsService::bumpPublicCache();

        return response()->json(null, 204);
    }
}
