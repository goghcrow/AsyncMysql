<?php

class MysqlScheduler extends Scheduler {
    // mysqlHash => [mysqli, [task, task, ...]]
    protected $waitingForRead = [];

    protected function beforeRun() {
        // 手动开启
        $this->newTask($this->ioPollTask());
    }

    protected function ioPoll($sec, $usec = 0) {
        if(empty($this->waitingForRead)) {
            return;
        }

        $links = $errors = $rejects = [];
        foreach ($this->waitingForRead as $val) {
            // $link = $val[0];
            $links[] = $errors[] = $rejects[] = $val[0];
        }

        // FIXME handle errors
        if (!mysqli::poll($links, $errors, $rejects, $sec, $usec)) {
            return;
        }

        // 粗暴的错误处理
        $throws = [];
        foreach(array_merge($errors, $rejects) as $link) {
            $throws[] = $link->error;
        }
        if($throws) {
            throw new CoMysqlException(sprintf("MySQLi Errors: %s", implode('|', $throws)));
        }

        // FIXME
        foreach ($links as $link) {
            $hash = spl_object_hash($link);
            list(, $tasks) = $this->waitingForRead[$hash];
            unset($this->waitingForRead[$hash]);

            foreach ($tasks as $task) {
                $this->schedule($task);
            }
        }
    }

    public function ioPollTask() {
        if(empty($this->waitingForRead)) {
            yield;
        } else {
            while (true) {
                if ($this->taskQueue->isEmpty()) {
                    // 节省cpu资源
                    $this->ioPoll(0, 1000);
                } else {
                    $this->ioPoll(0);
                }
                yield;
            }
        }
    }

    public function waitForRead(mysqli $link, Task $task) {
        $hash = spl_object_hash($link);
        if (isset($this->waitingForRead[$hash])) {
            $this->waitingForRead[$hash][1][] = $task;
        } else {
            $this->waitingForRead[$hash] = [$link, [$task]];
        }
    }

    // static syscall helper
    public static function syscallWaitForRead(mysqli $link) {
        return new SystemCall(
            function(Task $task, Scheduler $scheduler) use ($link) {
                // echo 'waitForRead' . PHP_EOL;
                $scheduler->waitForRead($link, $task);
            }
        );
    }
}
