<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function index(): View
    {
        Gate::authorize('viewAny', User::class);

        return view('admin.users.index', [
            'users' => User::with('roles')->orderBy('name')->paginate(25),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', User::class);

        return view('admin.users.create', ['user' => new User]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->users->create($request->safe()->except('role'), Role::from($request->validated('role')));

        return redirect()->route('admin.users.index')->with('status', __('app.users.created'));
    }

    public function edit(User $user): View
    {
        Gate::authorize('update', $user);

        return view('admin.users.edit', ['user' => $user]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->users->update($user, $request->safe()->except('role'), Role::from($request->validated('role')));

        return redirect()->route('admin.users.index')->with('status', __('app.users.updated'));
    }
}
