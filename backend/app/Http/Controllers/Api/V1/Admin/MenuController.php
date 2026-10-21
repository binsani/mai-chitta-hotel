<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\NavigationMenu;
use App\Services\CmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MenuController extends Controller
{
    public function __construct(private CmsService $cms) {}

    public function index(): JsonResponse
    {
        Gate::authorize('website.view');

        return response()->json([
            'data' => NavigationMenu::with('items')->orderBy('id')->get(),
        ]);
    }

    public function update(Request $request, string $slug): JsonResponse
    {
        Gate::authorize('website.edit');
        $menu = NavigationMenu::where('slug', $slug)->firstOrFail();

        $data = $request->validate([
            'items' => ['required', 'array', 'max:30'],
            'items.*.label' => ['required', 'string', 'max:120'],
            'items.*.url' => ['required', 'string', 'max:255'],
            'items.*.page_id' => ['nullable', 'integer', 'exists:pages,id'],
            'items.*.target' => ['sometimes', Rule::in(['_self', '_blank'])],
            'items.*.is_visible' => ['sometimes', 'boolean'],
            'items.*.sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $updated = $this->cms->syncMenu($menu, $data['items'], $request->user());

        return response()->json(['data' => $updated]);
    }
}
