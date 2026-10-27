<?php

namespace Tests\Feature\Pos;

use App\Enums\UserRole;
use App\Models\{Branch, Sale, User};
use App\Services\Pos\{Checkout, RefundSale};
use App\Services\Reporting\{BranchReports, ReportPeriod};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConcurrentReportsTest extends TestCase
{
    use DatabaseMigrations;

    public function test_refund_committed_mid_report_does_not_mix_read_snapshots(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Native read-consistency test requires MySQL and pcntl.');
        }
        $branch = Branch::create(['name' => 'Reporting', 'code' => 'REPORT', 'currency' => 'BDT', 'timezone' => 'Asia/Dhaka', 'tax_rate' => '0.00', 'is_active' => true]);
        $actor = User::factory()->create(['role' => UserRole::Admin]);
        $product = $branch->products()->create(['name' => 'Tea', 'sku' => 'TEA', 'price_minor' => 100, 'stock' => 10, 'is_active' => true]);
        $sale = app(Checkout::class)->complete($branch, $actor, ['currency' => 'BDT', 'expected_tax_basis_points' => 0, 'tendered' => '2.00', 'idempotency_key' => (string) Str::uuid(), 'items' => [['product_id' => $product->id, 'quantity' => 2, 'expected_price_minor' => 100]]]);
        $date = now($branch->timezone)->toDateString(); $period = new ReportPeriod($date, $date, $branch->timezone);
        DB::purge();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($sockets === false) throw new \RuntimeException('Cannot create synchronization sockets.');
        $pid = pcntl_fork();
        if ($pid === -1) throw new \RuntimeException('Cannot fork reporting worker.');
        if ($pid === 0) {
            fclose($sockets[0]); stream_set_timeout($sockets[1], 20);
            try {
                if (fread($sockets[1], 1) !== '1') throw new \RuntimeException('Parent did not reach sales aggregate.');
                app(RefundSale::class)->record($branch, Sale::findOrFail($sale->id), User::findOrFail($actor->id), ['idempotency_key' => (string) Str::uuid(), 'reason' => 'Concurrent return', 'items' => [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 1, 'expected_returned' => 0, 'restock' => false]]]);
                fwrite($sockets[1], '1'); DB::disconnect(); fclose($sockets[1]); exit(0);
            } catch (\Throwable $e) {
                fwrite($sockets[1], $e->getMessage()); DB::disconnect(); fclose($sockets[1]); exit(1);
            }
        }
        fclose($sockets[1]); stream_set_timeout($sockets[0], 20);
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $triggered = false;
        DB::listen(function ($query) use (&$triggered, $sockets) {
            if (! $triggered && str_contains($query->sql, 'AS sale_count')) {
                $triggered = true;
                fwrite($sockets[0], '1');
                $this->assertSame('1', fread($sockets[0], 1024), 'Concurrent refund must commit before the report continues.');
            }
        });
        try {
            $report = app(BranchReports::class)->snapshot(fn () => app(BranchReports::class)->sales($branch, $period));
            $this->assertTrue($triggered);
            $this->assertSame(200, $report['totals']['charged_minor']);
            $this->assertSame(0, $report['totals']['refunded_minor']);
            $next = app(BranchReports::class)->snapshot(fn () => app(BranchReports::class)->sales($branch, $period));
            $this->assertSame(100, $next['totals']['refunded_minor']);
            $this->assertSame(100, $next['totals']['net_minor']);
        } finally {
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            fclose($sockets[0]); pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
    }
}
