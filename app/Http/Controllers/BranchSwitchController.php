<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\Request;

class BranchSwitchController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate(['branch_id' => ['required', 'integer']]);
        $branch = Branch::accessibleTo($request->user())->where('is_active', true)->findOrFail($data['branch_id']);
        $request->session()->put('branch_id', $branch->id);

        return redirect()->route('dashboard')->with('status', 'Switched to '.$branch->name.'.');
    }
}
