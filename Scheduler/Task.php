<?php

class Task {
    protected $taskId;
    protected $coroutine;
    protected $sendValue = null;
    protected $beforeFirstYield = true;
    protected $exception = null;

    public function __construct($taskId, Generator $coroutine) {
        $this->taskId = $taskId;
        $this->coroutine = self::stackedCoroutine($coroutine);
    }

    public function getTaskId() {
        return $this->taskId;
    }

    public function getCoroutine() {
        return $this->coroutine;
    }

    public function setSendValue($sendValue) {
        $this->sendValue = $sendValue;
    }

    public function setException($exception) {
        $this->exception = $exception;
    }

    public function run() {
        if ($this->beforeFirstYield) {
            $this->beforeFirstYield = false;
            return $this->coroutine->current();
        } elseif ($this->exception) {
            $retval = $this->coroutine->throw($this->exception);
            $this->exception = null;
            return $retval;
        } else {
            $retval = $this->coroutine->send($this->sendValue);
            $this->sendValue = null;
            return $retval;
        }
    }

    public function isFinished() {
        return !$this->coroutine->valid();
    }


    // static helper

    public static function retval($value) {
        return new CoroutineReturnValue($value);
    }

    public static function plainval($value) {
        return new CoroutinePlainValue($value);
    }

    /**
     * 抛出stack中的Generator的异常
     * @param  Exception $e
     * @param  SplStack  $stack SplStack<Generator>
     * @throw  Exception
     */
    protected static function throwExceptionInStack(Exception $e, SplStack $stack) {
        while (!$stack->isEmpty()) {
            $generator = $stack->pop();
            assert($generator instanceof Generator);
            try {
                // is_callable([$generator, 'thorw'])
                $generator->throw($e);
                $e = null;
            } catch(Exception $e) {
            }
            if($e === null) {
                return;
            }
        }

        // 当statk为空，直接throw
        throw $e;
    }

    /**
     * 让协程支持嵌套子协程
     * 让协程调用者无感知运行子协程
     * !!! 注意：此方法不能用iterator_to_array获取值
     * 原因需要探究一下~~
     * @param  Generator $gen
     * @return Generator
     *
     * 20151001 修改异常处理逻辑：抽出单独方法处理
     */
    public static function stackedCoroutine(Generator $gen) {
        $stack = new SplStack;
        // $exception = null;

        while(true) {
            try {
                /*
                if ($exception) {
                    $gen->throw($exception);
                    $exception = null;
                    continue;
                }
                //*/

                $value = $gen->current();

                // 检查返回值是否是生成器，是生成器的则开始运行这个生成器，并把前一个协程压入堆栈里
                if ($value instanceof Generator) {
                    $stack->push($gen);
                    $gen = $value;
                    continue;
                }

                $isReturnValue = $value instanceof CoroutineReturnValue;

                // 迭代器没有被终止且当前值为返回值
                if (!$gen->valid() || $isReturnValue) {
                    // statck 为空，调用者不接受返回值
                    if ($stack->isEmpty()) {
                        return;
                    }

                    // 如果当前值为返回值类型，将返回值yield给调用方，堆栈弹出，继续执行前一个协程.
                    $gen = $stack->pop();
                    $gen->send($isReturnValue ? $value->getValue() : NULL);
                    continue;
                }

                if ($value instanceof CoroutinePlainValue) {
                    $value = $value->getValue();
                }

                try {
                    // 代理调用者和当前正在运行的子协程
                    $sendValue = (yield $gen->key() => $value);
                } catch (Exception $e) {
                    $gen->throw($e);
                    continue;
                }

                $gen->send($sendValue);
            } catch (Exception $e) {
                /*
                if ($stack->isEmpty()) {
                    throw $e;
                }

                $gen = $stack->pop();
                $exception = $e;
                //*/

                self::throwExceptionInStack($e, $stack);
            }
        }
    }
}

class CoroutineValueWrapper {
    protected $value;

    public function __construct($value) {
        $this->value = $value;
    }

    public function getValue() {
        return $this->value;
    }
}

class CoroutineReturnValue extends CoroutineValueWrapper { }
class CoroutinePlainValue extends CoroutineValueWrapper { }
