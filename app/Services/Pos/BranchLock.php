<?php

namespace App\Services\Pos;

use App\Models\Branch;
use App\Models\User;

class BranchLock
{
    // All stock, catalog, customer, payment and cash-session writes lock this row first.
    // This serializes competing branch writes on MySQL/PostgreSQL.
    public static function acquire(Branch $branch, User $actor): Branch
    {
        $locked = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
        $version = $actor->session_version;
        $actor->refresh();
        abort_unless($actor->is_active && ! $actor->must_change_password && $actor->session_version === $version, 403);
        abort_unless($locked->is_active && Branch::accessibleTo($actor)->whereKey($locked->id)->exists(), 403);

        return $locked;
    }
}
