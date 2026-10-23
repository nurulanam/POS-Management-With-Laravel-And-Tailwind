<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function __invoke(Request $request)
    {
        $branch = $request->attributes->get('currentBranch');
        abort_unless($branch, 403, 'An active branch assignment is required to view a team.');
        $users = $branch->users()->where('is_active', true)->orderBy('name')
            ->paginate(12, ['users.id', 'name', 'email', 'role']);

        return view('team.index', compact('branch', 'users'));
    }
}
