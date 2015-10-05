<?php

class StreamScheduler extends Scheduler {
    // resourceID => [socket, tasks]
    protected $waitingForRead = [];
    protected $waitingForWrite = [];

    protected function beforeRun() {
        $this->newTask($this->ioPollTask());
    }

    protected function ioPoll($timeout) {
        if (empty($this->waitingForRead) && empty($this->waitingForWrite)) {
            return;
        }

        $rSocks = [];
        foreach ($this->waitingForRead as list($socket)) {
            $rSocks[] = $socket;
        }

        $wSocks = [];
        foreach ($this->waitingForWrite as list($socket)) {
            $wSocks[] = $socket;
        }

        $eSocks = []; // dummy

        if (!stream_select($rSocks, $wSocks, $eSocks, $timeout)) {
            return;
        }

        foreach ($rSocks as $socket) {
            list(, $tasks) = $this->waitingForRead[(int) $socket];
            unset($this->waitingForRead[(int) $socket]);

            foreach ($tasks as $task) {
                $this->schedule($task);
            }
        }

        foreach ($wSocks as $socket) {
            list(, $tasks) = $this->waitingForWrite[(int) $socket];
            unset($this->waitingForWrite[(int) $socket]);

            foreach ($tasks as $task) {
                $this->schedule($task);
            }
        }
    }

    protected function ioPollTask() {
        while (true) {
            if ($this->taskQueue->isEmpty()) {
                $this->ioPoll(null);
            } else {
                $this->ioPoll(0);
            }
            yield;
        }
    }

    public function waitForRead($socket, Task $task) {
        if (isset($this->waitingForRead[(int) $socket])) {
            $this->waitingForRead[(int) $socket][1][] = $task;
        } else {
            $this->waitingForRead[(int) $socket] = [$socket, [$task]];
        }
    }

    public function waitForWrite($socket, Task $task) {
        if (isset($this->waitingForWrite[(int) $socket])) {
            $this->waitingForWrite[(int) $socket][1][] = $task;
        } else {
            $this->waitingForWrite[(int) $socket] = [$socket, [$task]];
        }
    }


    // static syscall helper

    public static function syscallWaitForRead($socket) {
        return new SystemCall(
            function(Task $task, Scheduler $scheduler) use ($socket) {
                // echo 'waitForRead' . PHP_EOL;
                $scheduler->waitForRead($socket, $task);
            }
        );
    }

    public static function syscallWaitForWrite($socket) {
        return new SystemCall(
            function(Task $task, Scheduler $scheduler) use ($socket) {
                // echo 'waitForWrite' . PHP_EOL;
                $scheduler->waitForWrite($socket, $task);
            }
        );
    }
}
