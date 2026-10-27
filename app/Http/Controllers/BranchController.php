<?php

namespace App\Http\Controllers;

use App\Http\Requests\BranchRequest;
use App\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BranchController extends Controller
{
    public function index()
    {
        return view('branches.index', ['branches' => Branch::withCount('users')->orderBy('name')->paginate(12)]);
    }

    public function create()
    {
        return $this->form(new Branch(['is_active' => true, 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'tax_rate' => '0.00']));
    }

    public function store(BranchRequest $request)
    {
        $branch = Branch::create($request->validated());
        if ($branch->is_active) {
            $request->session()->put('branch_id', $branch->id);
        }

        return redirect()->route('branches.index')->with('status', 'Branch created. You can now assign staff to it.');
    }

    public function edit(Branch $branch)
    {
        return $this->form($branch);
    }

    public function update(BranchRequest $request, Branch $branch)
    {
        DB::transaction(function () use ($request, $branch) {
            $branches = Branch::orderBy('id')->lockForUpdate()->get();
            $current = $branches->find($branch->id);
            if ($current->is_active && ! $request->boolean('is_active') && $branches->where('is_active', true)->count() <= 1) {
                throw ValidationException::withMessages(['is_active' => 'At least one active branch must remain.']);
            }
            if ($current->currency !== $request->input('currency') && ($current->products()->exists() || $current->sales()->exists() || $current->cashSessions()->exists())) {
                throw ValidationException::withMessages(['currency' => 'Currency cannot change after products, sales, or till sessions exist. Use a separate branch for another currency.']);
            }
            $current->update($request->validated());
        });

        return redirect()->route('branches.edit', $branch)->with('status', 'Branch settings saved.');
    }

    private function form(Branch $branch)
    {
        return view('branches.form', [
            'branch' => $branch,
            'currencies' => config('pos.currencies'),
            'timezones' => \DateTimeZone::listIdentifiers(\DateTimeZone::ALL),
        ]);
    }
}
