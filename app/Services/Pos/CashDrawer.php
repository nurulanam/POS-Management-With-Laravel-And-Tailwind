<?php

namespace App\Services\Pos;

use App\Models\{Branch, CashEntry, CashSession, User};
use App\Support\Money;
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CashDrawer
{
    public const LIMIT = 2_000_000_000_000;

    public function enable(Branch $branch, User $actor): void
    {
        DB::transaction(function () use ($branch, $actor) {
            $branch = BranchLock::acquire($branch, $actor);
            Gate::forUser($actor)->authorize('manage-cash');
            if (! $branch->cash_tracking_enabled) {
                $branch->forceFill(['cash_tracking_enabled' => true, 'cash_tracking_enabled_at' => now('UTC'), 'cash_tracking_enabled_by' => $actor->id])->save();
            }
        }, 3);
    }

    public function open(Branch $branch, User $actor, array $data): CashSession
    {
        return DB::transaction(function () use ($branch, $actor, $data) {
            $branch = BranchLock::acquire($branch, $actor);
            $amount = Money::parse($data['opening'], $branch->currency, 'opening', self::LIMIT);
            $hash = $this->hash([$amount, $data['currency']]);
            $existing = $branch->cashSessions()->where('opening_key', $data['idempotency_key'])->first();
            if ($existing) {
                $this->replay($existing->user_id, $actor, $existing->opening_hash, $hash);
                return $existing;
            }
            if (! $branch->cash_tracking_enabled || $data['currency'] !== $branch->currency) {
                $this->invalid('opening', 'Enable tracking and reload this branch before opening a till.');
            }
            if ($branch->cashSessions()->where('active_owner_id', $actor->id)->exists()) {
                $this->invalid('opening', 'You already have an open till in this branch. Close it before opening another.');
            }
            return $branch->cashSessions()->create([
                'user_id' => $actor->id, 'active_owner_id' => $actor->id, 'owner_name' => $actor->name,
                'branch_name' => $branch->name, 'reference' => 'T-'.Str::ulid(), 'currency' => $branch->currency,
                'timezone' => $branch->timezone, 'opening_minor' => $amount, 'expected_minor' => $amount,
                'opening_key' => $data['idempotency_key'], 'opening_hash' => $hash, 'version' => 0, 'opened_at' => now('UTC'),
            ]);
        }, 3);
    }

    /** Called only inside checkout/refund transactions, after the branch lock and replay lookup. */
    public function forPayment(Branch $branch, User $actor, array $data): ?CashSession
    {
        $id = (int) ($data['cash_session_id'] ?? 0);
        if (! $branch->cash_tracking_enabled && ! $id) return null;
        $session = $branch->cashSessions()->where('user_id', $actor->id)->whereNull('closed_at')->lockForUpdate()->find($id);
        if (! $branch->cash_tracking_enabled || ! $session || $session->currency !== $branch->currency) {
            $this->invalid('cash_session_id', 'An open till owned by you is required. Open your till, then reload this form.');
        }
        return $session;
    }

    /** Append-only balance update; callers must hold the branch/session lock in the same transaction. */
    public function append(CashSession $session, User $actor, int $delta, string $kind, string $reason, array $source = []): CashEntry
    {
        if ($session->closed_at) $this->invalid('cash_session_id', 'This till is closed. Reload before continuing.');
        $balance = $session->expected_minor + $delta;
        if ($balance < 0 || $balance > self::LIMIT) {
            $this->invalid('cash_session_id', 'This movement exceeds the till balance or supported cash limit. Record actual funding or a cash drop first.');
        }
        $entry = $session->entries()->create([
            ...$source, 'branch_id' => $session->branch_id, 'user_id' => $actor->id, 'staff_name' => $actor->name,
            'kind' => $kind, 'delta_minor' => $delta, 'balance_minor' => $balance, 'reason' => $reason, 'created_at' => now('UTC'),
        ]);
        $session->update(['expected_minor' => $balance, 'version' => $session->version + 1]);
        return $entry;
    }

    public function movement(Branch $branch, CashSession $session, User $actor, array $data): CashEntry
    {
        return DB::transaction(function () use ($branch, $session, $actor, $data) {
            $branch = BranchLock::acquire($branch, $actor);
            Gate::forUser($actor)->authorize('manage-cash');
            $session = $branch->cashSessions()->lockForUpdate()->findOrFail($session->id);
            $amount = Money::parse($data['amount'], $session->currency, 'amount', self::LIMIT);
            if ($amount <= 0) $this->invalid('amount', 'Cash movements must be positive. Choose cash in or cash out.');
            $hash = $this->hash([$session->id, $data['kind'], $amount, $data['reason'], (int) $data['expected_version']]);
            $existing = CashEntry::where('branch_id', $branch->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                $this->replay($existing->user_id, $actor, $existing->request_hash, $hash);
                return $existing;
            }
            $this->fresh($session, $data);
            return $this->append($session, $actor, $data['kind'] === 'in' ? $amount : -$amount, $data['kind'], $data['reason'], [
                'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
            ]);
        }, 3);
    }

    public function close(Branch $branch, CashSession $session, User $actor, array $data): CashSession
    {
        return DB::transaction(function () use ($branch, $session, $actor, $data) {
            $branch = BranchLock::acquire($branch, $actor);
            $session = $branch->cashSessions()->lockForUpdate()->findOrFail($session->id);
            if ($session->user_id !== $actor->id) Gate::forUser($actor)->authorize('manage-cash');
            $counted = Money::parse($data['counted'], $session->currency, 'counted', self::LIMIT);
            $hash = $this->hash([$session->id, $counted, $data['reason'], (int) $data['expected_version']]);
            $existing = $branch->cashSessions()->where('close_key', $data['idempotency_key'])->first();
            if ($existing) {
                $this->replay($existing->closed_by, $actor, $existing->close_hash, $hash);
                return $existing;
            }
            $this->fresh($session, $data);
            $session->update([
                'closed_at' => now('UTC'), 'closed_by' => $actor->id, 'closer_name' => $actor->name, 'active_owner_id' => null,
                'counted_minor' => $counted, 'variance_minor' => $counted - $session->expected_minor,
                'close_reason' => $data['reason'], 'close_key' => $data['idempotency_key'], 'close_hash' => $hash,
            ]);
            return $session;
        }, 3);
    }

    public function review(Branch $branch, CashSession $session, User $actor, array $data): CashSession
    {
        return DB::transaction(function () use ($branch, $session, $actor, $data) {
            $branch = BranchLock::acquire($branch, $actor);
            Gate::forUser($actor)->authorize('manage-cash');
            $session = $branch->cashSessions()->lockForUpdate()->findOrFail($session->id);
            $hash = $this->hash([$session->id, $data['note']]);
            $existing = $branch->cashSessions()->where('review_key', $data['idempotency_key'])->first();
            if ($existing) {
                $this->replay($existing->reviewed_by, $actor, $existing->review_hash, $hash);
                return $existing;
            }
            if (! $session->closed_at || ! $session->variance_minor || $session->reviewed_at) {
                $this->invalid('note', 'Only an unreviewed, closed till with a variance can be reviewed.');
            }
            $session->update([
                'reviewed_by' => $actor->id, 'reviewer_name' => $actor->name, 'reviewed_at' => now('UTC'),
                'review_note' => $data['note'], 'review_key' => $data['idempotency_key'], 'review_hash' => $hash,
            ]);
            return $session;
        }, 3);
    }

    private function fresh(CashSession $session, array $data): void
    {
        if ($session->closed_at || $session->version !== (int) $data['expected_version']) {
            $this->invalid('expected_version', 'The till changed or was closed while this form was open. Reload, review its activity, and count again before submitting.');
        }
    }

    private function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function replay(int $userId, User $actor, string $stored, string $hash): void
    {
        abort_unless($userId === $actor->id, 403);
        abort_unless(hash_equals($stored, $hash), 409, 'This key has already been used for a different cash operation.');
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
