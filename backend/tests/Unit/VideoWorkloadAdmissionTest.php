<?php

namespace Tests\Unit;

use App\Services\VideoWorkloadAdmission;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class VideoWorkloadAdmissionTest extends TestCase
{
    public function test_sqlite_callback_runs_inside_a_transaction_and_commits(): void
    {
        $connection = DB::connection();
        $connection->statement('CREATE TABLE admission_checks (id INTEGER)');
        $result = app(VideoWorkloadAdmission::class)->underExclusiveAdmission(function () use ($connection): string {
            $this->assertSame(1, $connection->transactionLevel());
            $connection->table('admission_checks')->insert(['id' => 1]);

            return 'admitted';
        });
        $this->assertSame('admitted', $result);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertSame(1, $connection->table('admission_checks')->count());
    }

    public function test_callback_exception_rolls_back_and_is_not_swallowed(): void
    {
        $connection = DB::connection();
        $connection->statement('CREATE TABLE admission_checks (id INTEGER)');
        $failure = new RuntimeException('callback failed');
        try {
            app(VideoWorkloadAdmission::class)->underExclusiveAdmission(function () use ($connection, $failure): void {
                $connection->table('admission_checks')->insert(['id' => 1]);
                throw $failure;
            });
            $this->fail('Expected callback exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertSame(0, $connection->table('admission_checks')->count());
    }

    public function test_postgresql_lock_is_acquired_inside_the_transaction_before_callback(): void
    {
        $connection = Mockery::mock(Connection::class);
        $inside = false;
        $locked = false;
        $connection->shouldReceive('getDriverName')->andReturn('pgsql');
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldReceive('transaction')->once()->with(Mockery::type('callable'), 1)
            ->andReturnUsing(function (callable $callback) use (&$inside): mixed {
                $inside = true;
                $result = $callback();
                $inside = false;

                return $result;
            });
        $connection->shouldReceive('select')->once()
            ->with('SELECT pg_advisory_xact_lock(?)', [7483102602], false)
            ->andReturnUsing(function () use (&$locked, &$inside): array {
                $this->assertTrue($inside);
                $locked = true;

                return [];
            });
        DB::shouldReceive('connection')->once()->andReturn($connection);
        $this->assertSame(42, (new VideoWorkloadAdmission)->underExclusiveAdmission(function () use (&$locked, &$inside): int {
            $this->assertTrue($inside);
            $this->assertTrue($locked);

            return 42;
        }));
        $this->assertFalse($inside);
    }

    public function test_postgresql_rejects_an_outer_transaction_that_would_extend_the_lock(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('pgsql');
        $connection->shouldReceive('transactionLevel')->once()->andReturn(1);
        $connection->shouldNotReceive('transaction');
        $connection->shouldNotReceive('select');
        DB::shouldReceive('connection')->once()->andReturn($connection);
        $this->expectException(LogicException::class);
        (new VideoWorkloadAdmission)->underExclusiveAdmission(fn () => $this->fail('Must not run.'));
    }
}
