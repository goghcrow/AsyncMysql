<?php

class SystemCall {
    protected $callback;

    public function __construct(callable $callback) {
        $this->callback = $callback;
    }

    public function __invoke(Task $task, Scheduler $scheduler) {
        $callback = $this->callback; // Yes, PHP sucks
        return $callback($task, $scheduler);
    }
}
