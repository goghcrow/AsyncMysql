<?php
error_reporting(E_ALL);

spl_autoload_register(function($className) {
    $map = [
        'Task'              =>  __DIR__ . '/Scheduler/Task.php',
        'Scheduler'         =>  __DIR__ . '/Scheduler/Scheduler.php',
        'SystemCall'        =>  __DIR__ . '/Scheduler/SystemCall.php',
        'StreamScheduler'   =>  __DIR__ . '/Scheduler/StreamScheduler.php',
        'MysqlScheduler'    =>  __DIR__ . '/Scheduler/MysqlScheduler.php',
    ];
    if(isset($map[$className])) {
        require_once $map[$className];
    }
}, true);

class CoMysql {
    private $queryId = 0;
    private $queryLink = [];

    public function __construct() {

    }

    public function createLink() {
        // FIXME 从空闲取，无，新建，有新家，加入，yield
        yield Task::retval(new mysqli('p:127.0.0.1', 'root', '111111', 'meixiansong'));
    }

    public function query($sql) {
        $queryId = $this->queryId++;
        $link = (yield $this->createLink());
        $link->query($sql, MYSQLI_ASYNC);
        $this->queryLink[$queryId] = $link;
        yield Task::retval($queryId);
    }

    public function poll() {
        foreach($this->queryLink as $link) {
            yield MysqlScheduler::syscallWaitForRead($link);
        }

        // ioPoll 结束 kill掉 pollTask
        yield Scheduler::syscallKillTask(2);
    }

    public function fetch($queryId) {
        $link = $this->queryLink[$queryId];
        if ($result = $link->reap_async_query()) {
            yield Task::retval($result->fetch_row());
            if (is_object($result)) {
                mysqli_free_result($result);
            }
        } else {
            // FIXME
            throw new Exception(sprintf("MySQLi Error: %s", mysqli_error($link)));
        }
    }
}



// cost 1sec
function task() {
    $coMysql = new CoMysql;
    $q1 = (yield $coMysql->query('select sleep(1)'));
    $q2 = (yield $coMysql->query('select sleep(1)'));
    $q3 = (yield $coMysql->query('select "link3"'));

    $coMysql->poll();

    $ret1 = (yield $coMysql->fetch($q1));
    var_dump($ret1);
    $ret2 = (yield $coMysql->fetch($q2));
    var_dump($ret2);
    $ret3 = (yield $coMysql->fetch($q3));
    var_dump($ret3);
}

// cost 1sec
function task2() {
    $coMysql = new CoMysql;
    $map = [];
    for ($i=0; $i < 10; $i++) {
        $map[$i] = (yield $coMysql->query('select sleep(1)'));
    }

    $coMysql->poll();

    for ($i=0; $i < 10; $i++) {
        $ret = (yield $coMysql->fetch($map[$i]));
    }
}


$start = microtime(true);
$scheduler = new MysqlScheduler;
$scheduler->newTask(task());
$scheduler->newTask(task2());
$scheduler->run();
echo microtime(true) - $start;
