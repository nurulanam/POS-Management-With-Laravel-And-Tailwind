<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Pos\BranchLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $customers = $request->attributes->get('currentBranch')->customers()
            ->when(isset($filter['q']), fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$filter['q'].'%')->orWhere('phone', 'like', '%'.$filter['q'].'%')->orWhere('email', 'like', '%'.$filter['q'].'%')))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('customers.index', compact('customers'));
    }

    public function create()
    {
        return view('customers.form', ['customer' => new Customer(['is_active' => true])]);
    }

    public function edit(Request $request, Customer $customer)
    {
        abort_unless($customer->branch_id === $request->attributes->get('currentBranch')->id, 404);

        return view('customers.form', compact('customer'));
    }

    public function store(Request $request)
    {
        return $this->save($request);
    }

    public function update(Request $request, Customer $customer)
    {
        abort_unless($customer->branch_id === $request->attributes->get('currentBranch')->id, 404);

        return $this->save($request, $customer);
    }

    private function save(Request $request, ?Customer $customer = null)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'], 'address' => ['nullable', 'string', 'max:1000'],
            ...($customer ? ['is_active' => ['required', 'boolean']] : []),
        ]);
        DB::transaction(function () use ($request, $customer, $data) {
            $branch = BranchLock::acquire($request->attributes->get('currentBranch'), $request->user());
            if ($customer) {
                Gate::authorize('manage-inventory');
                $customer->update($data);
            } else {
                $branch->customers()->create([...$data, 'is_active' => true]);
            }
        }, 3);

        return redirect()->route('customers.index')->with('status', 'Customer saved. Previous receipts retain their original customer details.');
    }
}
