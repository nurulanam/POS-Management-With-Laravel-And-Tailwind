<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\StaffRequest;
use App\Models\Branch;
use App\Models\User;
use App\Services\StaffService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'branch' => ['nullable', 'integer', 'exists:branches,id'],
        ]);
        $users = User::with('branches')
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->where(fn ($query) => $query->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")))
            ->when($filters['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('is_active', $status === 'active'))
            ->when($filters['branch'] ?? null, fn ($query, $id) => $query->whereHas('branches', fn ($branches) => $branches->whereKey($id)))
            ->orderBy('name')->paginate(12)->withQueryString();

        return view('users.index', ['users' => $users, 'branches' => Branch::orderBy('name')->get(), 'roles' => UserRole::cases()]);
    }

    public function create()
    {
        return $this->form((new User)->forceFill(['role' => UserRole::Cashier, 'is_active' => true]));
    }

    public function store(StaffRequest $request, StaffService $service)
    {
        $service->save($request->validated(), $request->user());

        return redirect()->route('users.index')->with('status', 'Staff account created. Share the temporary password securely; it must be changed at first sign-in.');
    }

    public function edit(User $user)
    {
        return $this->form($user);
    }

    public function update(StaffRequest $request, User $user, StaffService $service)
    {
        $updated = $service->save($request->validated(), $request->user(), $user);
        if ($updated->id === $request->user()->id) {
            Auth::guard('web')->setUser($updated);
            $request->session()->put('user_version', $updated->session_version);
        }

        return redirect()->route('users.index')->with('status', 'Staff account updated. Previous sessions and API tokens have been revoked.');
    }

    private function form(User $user)
    {
        return view('users.form', [
            'staff' => $user,
            'roles' => UserRole::cases(),
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(),
            'assigned' => $user->exists ? $user->branches()->pluck('branches.id')->all() : [],
        ]);
    }
}
