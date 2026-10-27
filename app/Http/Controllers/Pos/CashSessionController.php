<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\CashSession;
use App\Services\Pos\CashDrawer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CashSessionController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'closed', 'pending', 'reviewed'])],
            'basis' => ['nullable', Rule::in(['opened', 'closed'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
        ]);
        $branch = $request->attributes->get('currentBranch');
        $query = $branch->cashSessions()->visibleTo($request->user());
        $status = $filter['status'] ?? null;
        if ($status === 'open') $query->whereNull('closed_at');
        if (in_array($status, ['closed', 'pending', 'reviewed'])) $query->whereNotNull('closed_at');
        if ($status === 'pending') $query->where('variance_minor', '!=', 0)->whereNull('reviewed_at');
        if ($status === 'reviewed') $query->whereNotNull('reviewed_at');
        $column = ($filter['basis'] ?? 'opened') === 'closed' ? 'closed_at' : 'opened_at';
        if (isset($filter['from'])) $query->where($column, '>=', CarbonImmutable::parse($filter['from'], $branch->timezone)->startOfDay()->utc());
        if (isset($filter['to'])) $query->where($column, '<', CarbonImmutable::parse($filter['to'], $branch->timezone)->addDay()->startOfDay()->utc());
        return view('cash.index', [
            'sessions' => $query->orderByDesc('id')->paginate(20)->withQueryString(),
            'openSession' => $branch->cashSessions()->where('active_owner_id', $request->user()->id)->first(),
            'openingKey' => (string) Str::uuid(),
        ]);
    }

    public function enable(Request $request, CashDrawer $drawer)
    {
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $drawer->enable($request->attributes->get('currentBranch'), $request->user());
        return redirect()->route('cash.index')->with('status', 'Cash tracking enabled. Open a till before processing new cash sales or refunds.');
    }

    public function store(Request $request, CashDrawer $drawer)
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'], 'opening' => ['required', 'string', 'max:20'],
            'currency' => ['required', 'string', 'size:3'], 'confirmed' => ['required', 'accepted'],
        ]);
        $session = $drawer->open($request->attributes->get('currentBranch'), $request->user(), $data);
        return redirect()->route('cash.show', $session)->with('status', 'Till opened. Cash activity will be recorded against this session.');
    }

    public function show(Request $request, CashSession $cashSession)
    {
        $session = $this->scoped($request, $cashSession);
        $totals = $session->entries()->selectRaw('kind, SUM(delta_minor) as amount')->groupBy('kind')->pluck('amount', 'kind');
        return view('cash.show', [
            'session' => $session, 'totals' => $totals, 'entries' => $session->entries()->orderByDesc('id')->paginate(30),
            'movementKey' => (string) Str::uuid(), 'closeKey' => (string) Str::uuid(), 'reviewKey' => (string) Str::uuid(),
        ]);
    }

    public function movement(Request $request, CashSession $cashSession, CashDrawer $drawer)
    {
        $session = $this->scoped($request, $cashSession);
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'], 'kind' => ['required', Rule::in(['in', 'out'])],
            'amount' => ['required', 'string', 'max:20'], 'reason' => ['required', 'string', 'min:3', 'max:500'],
            'expected_version' => ['required', 'integer', 'min:0'], 'confirmed' => ['required', 'accepted'],
        ]);
        $drawer->movement($request->attributes->get('currentBranch'), $session, $request->user(), $data);
        return redirect()->route('cash.show', $session)->with('status', 'Cash movement recorded.');
    }

    public function close(Request $request, CashSession $cashSession, CashDrawer $drawer)
    {
        $session = $this->scoped($request, $cashSession);
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'], 'counted' => ['required', 'string', 'max:20'],
            'reason' => ['required', 'string', 'min:3', 'max:500'], 'expected_version' => ['required', 'integer', 'min:0'],
            'confirmed' => ['required', 'accepted'],
        ]);
        $drawer->close($request->attributes->get('currentBranch'), $session, $request->user(), $data);
        return redirect()->route('cash.show', $session)->with('status', 'Till closed. The count and variance are final; no further activity can enter this session.');
    }

    public function review(Request $request, CashSession $cashSession, CashDrawer $drawer)
    {
        $session = $this->scoped($request, $cashSession);
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'], 'note' => ['required', 'string', 'min:3', 'max:500'],
            'confirmed' => ['required', 'accepted'],
        ]);
        $drawer->review($request->attributes->get('currentBranch'), $session, $request->user(), $data);
        return redirect()->route('cash.show', $session)->with('status', 'Variance review recorded. Original amounts remain unchanged.');
    }

    private function scoped(Request $request, CashSession $session): CashSession
    {
        return $request->attributes->get('currentBranch')->cashSessions()->visibleTo($request->user())->findOrFail($session->id);
    }
}
