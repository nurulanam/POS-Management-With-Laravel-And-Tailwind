<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Services\Pos\BranchLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Validation\{Rule, ValidationException};

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'archived'])]]);
        $suppliers = $request->attributes->get('currentBranch')->suppliers()
            ->when(isset($filter['q']), fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$filter['q'].'%')->orWhere('code', 'like', '%'.$filter['q'].'%')))
            ->when(isset($filter['status']), fn ($q) => $q->where('is_active', $filter['status'] === 'active'))
            ->orderBy('name')->paginate(20)->withQueryString();
        return view('suppliers.index', compact('suppliers'));
    }

    public function create() { return view('suppliers.form', ['supplier' => new Supplier(['is_active' => true])]); }

    public function edit(Request $request, Supplier $supplier)
    {
        $supplier = $request->attributes->get('currentBranch')->suppliers()->findOrFail($supplier->id);
        return view('suppliers.form', compact('supplier'));
    }

    public function store(Request $request) { return $this->save($request); }

    public function update(Request $request, Supplier $supplier) { return $this->save($request, $supplier); }

    private function save(Request $request, ?Supplier $supplier = null)
    {
        DB::transaction(function () use ($request, $supplier) {
            $branch = BranchLock::acquire($request->attributes->get('currentBranch'), $request->user());
            Gate::forUser($request->user())->authorize('manage-purchasing');
            if ($supplier) $supplier = $branch->suppliers()->lockForUpdate()->findOrFail($supplier->id);
            if (is_string($request->input('code'))) $request->merge(['code' => strtoupper(trim($request->input('code')))]);
            $data = $request->validate([
                'code' => ['required', 'string', 'max:60', 'regex:/^[A-Z0-9._-]+$/', Rule::unique('suppliers')->where('branch_id', $branch->id)->ignore($supplier)],
                'name' => ['required', 'string', 'max:255'], 'contact_name' => ['nullable', 'string', 'max:255'],
                'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'],
                'address' => ['nullable', 'string', 'max:500'], 'notes' => ['nullable', 'string', 'max:1000'],
                'is_active' => ['required', 'boolean'], ...($supplier ? ['expected_version' => ['required', 'integer', 'min:1']] : []),
            ]);
            if ($supplier && (int) $data['expected_version'] !== $supplier->version) {
                throw ValidationException::withMessages(['expected_version' => 'Supplier details changed. Reload and review before saving.']);
            }
            unset($data['expected_version']);
            if ($supplier) $supplier->update([...$data, 'version' => $supplier->version + 1]);
            else $branch->suppliers()->create([...$data, 'version' => 1]);
        }, 3);
        return redirect()->route('suppliers.index')->with('status', 'Supplier saved. Existing orders retain their original supplier details.');
    }
}
