<?php

class Scheduler {
    protected $maxTaskId = 0;
    protected $taskMap = []; // taskId => task
    protected $taskQueue;

    public function __construct() {
        $this->taskQueue = new SplQueue;
    }

    public function newTask(Generator $coroutine) {
        $tid = ++$this->maxTaskId;
        $task = new Task($tid, $coroutine);
        $this->taskMap[$tid] = $task;
        $this->schedule($task);
        return $tid;
    }

    public function schedule(Task $task) {
        $this->taskQueue->enqueue($task);
    }

    public function killTask($tid) {
        if (!isset($this->taskMap[$tid])) {
            return false;
        }
        unset($this->taskMap[$tid]);
        foreach ($this->taskQueue as $i => $task) {
            if ($task->getTaskId() === $tid) {
                unset($this->taskQueue[$i]);
                break;
            }
        }
        return true;
    }

    protected function beforeRun()
    {

    }

    public function run() {
        $this->beforeRun();

        // $i = 0;
        while (!$this->taskQueue->isEmpty()) {
            // echo ++$i . '. ';

            $task = $this->taskQueue->dequeue();
            $retval = $task->run();

            if ($retval instanceof SystemCall) {
                // echo 'invoke syscall ';
                try {
                    $retval($task, $this);
                } catch (Exception $e) {
                    $task->setException($e);
                    $this->schedule($task);
                }
                continue;
            }

            if ($task->isFinished()) {
                unset($this->taskMap[$task->getTaskId()]);
            } else {
                $this->schedule($task);
            }
        }
    }


    // static syscall helper

    public static function syscallGetTaskId() {
        return new SystemCall(function(Task $task, Scheduler $scheduler) {
            // echo 'getTaskId' . PHP_EOL;
            $task->setSendValue($task->getTaskId());
            $scheduler->schedule($task);
        });
    }

    public static function syscallNewTask(Generator $coroutine) {
        return new SystemCall(
            function(Task $task, Scheduler $scheduler) use ($coroutine) {
                // echo 'newTask' . PHP_EOL;
                $task->setSendValue($scheduler->newTask($coroutine));
                $scheduler->schedule($task);
            }
        );
    }

    public static function syscallKillTask($tid, callable $callback = null) {
        return new SystemCall(
            function(Task $task, Scheduler $scheduler) use ($tid, $callback) {
                // echo 'killTask' . PHP_EOL;
                if ($scheduler->killTask($tid)) {
                    if($callback) {
                        $callback();
                    }
                    $scheduler->schedule($task);
                } else {
                    throw new InvalidArgumentException('Invalid task ID!');
                }
            }
        );
    }

    // FIXME
    public static function syscallFork() {
        return new SystemCall(
            function(Task $task, Scheduler $scheduler) {
                // echo 'fork' . PHP_EOL;
                $tid = $scheduler->newTask(/*clone*/$task->getCoroutine());
                $task->setSendValue($tid);
                $scheduler->schedule($task);
            }
        );
    }
}
