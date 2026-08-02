<?php

declare(strict_types=1);

namespace Thrun\Laravel\Console;

use Async\Scope;
use Async\TaskSet;
use Closure;

/**
 * The supervisors a `thrun:work` process is running, and the services that
 * outlive none of them.
 *
 * Two groups with different endings is the whole of it: a supervisor ends when
 * its work does, and the process must stay up for the others; the RPC server and
 * the stats printer never end at all, so somebody has to stop them once there is
 * nothing left to serve.
 */
final readonly class SupervisorTasks
{
    /**
     * @param TaskSet $supervisors each task runs one supervisor to completion
     * @param Scope   $services    tasks that only make sense while a supervisor runs
     */
    public function __construct(
        private TaskSet $supervisors,
        private Scope $services,
    ) {
    }

    /**
     * Run until every supervisor has finished, then stop the services.
     *
     * A supervisor that returns has finished its work — a restart signal, a job
     * limit, a queue that ran dry — and the ones still working are left alone
     * with their jobs in flight. A supervisor that *fails* takes the rest with
     * it: whatever broke is likely to break them too, and a worker group running
     * on after a crash nobody can explain is worse than a stopped process.
     *
     * @param Closure(\Exception): void $reportFailure called once per failed supervisor, before the rest are stopped
     *
     * @return bool whether any supervisor failed
     */
    public function awaitAll(Closure $reportFailure): bool
    {
        $failed = false;

        while ($this->supervisors->count() !== 0) {
            try {
                $this->supervisors->joinNext()->await();
            } catch (\Cancellation) {
            } catch (\Exception $e) {
                $failed = true;

                $reportFailure($e);

                $this->supervisors->cancel();
            }
        }

        $this->services->cancel();

        return $failed;
    }
}
