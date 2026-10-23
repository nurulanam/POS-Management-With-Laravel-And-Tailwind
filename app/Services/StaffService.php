<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StaffService
{
    public function save(array $data, User $actor, ?User $staff = null): User
    {
        return DB::transaction(function () use ($data, $actor, $staff) {
            // Serialize administrator updates, including the last-admin check.
            $users = User::orderBy('id')->lockForUpdate()->get();
            $currentActor = $users->find($actor->id);
            abort_unless($currentActor?->is_active && $currentActor->isAdmin(), 403);
            $user = $staff ? $users->find($staff->id) : new User;
            $role = UserRole::from($data['role']);
            $active = (bool) $data['is_active'];
            if ($staff && $actor->id === $staff->id && (! $active || $role !== UserRole::Admin)) {
                throw ValidationException::withMessages(['role' => 'You cannot deactivate or demote your own administrator account.']);
            }
            if ($staff && $user->is_active && $user->isAdmin() && (! $active || $role !== UserRole::Admin)) {
                $others = $users->contains(fn (User $other) => $other->id !== $user->id && $other->is_active && $other->isAdmin());
                if (! $others) {
                    throw ValidationException::withMessages(['role' => 'At least one active administrator must remain.']);
                }
            }
            $user->name = $data['name'];
            $user->email = $data['email'];
            $user->role = $role;
            $user->is_active = $active;
            if (! empty($data['password'])) {
                $user->password = $data['password'];
                $user->must_change_password = true;
            }
            $user->session_version = ($user->session_version ?? 0) + 1;
            $user->remember_token = Str::random(60);
            $user->save();
            $user->branches()->sync($role === UserRole::Admin ? [] : ($data['branch_ids'] ?? []));
            $user->tokens()->delete();

            return $user;
        });
    }
}
