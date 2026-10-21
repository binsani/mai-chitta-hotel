<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserAdminService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class UserController extends Controller
{
    public function __construct(private UserAdminService $service) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', User::class);
        $q = trim((string) $request->query('search', ''));

        $users = User::with('roles')
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('name', 'like', "%$q%")->orWhere('email', 'like', "%$q%")))
            ->orderBy('name')
            ->paginate(min(100, max(5, (int) $request->query('per_page', 25))));

        return UserResource::collection($users);
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        return new UserResource($user->load('roles'));
    }

    public function store(SaveUserRequest $request)
    {
        $user = $this->service->create($request->validated(), $request->user());

        return (new UserResource($user->load('roles')))->response()->setStatusCode(201);
    }

    public function update(SaveUserRequest $request, User $user): UserResource
    {
        return new UserResource($this->service->update($user, $request->validated(), $request->user()));
    }
}
