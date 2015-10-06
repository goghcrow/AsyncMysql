<?php
// ubuntu install mysqlnd
// echo "deb http://security.ubuntu.com/ubuntu precise-security main universe" >> /etc/apt/sources.list
// apt-get update && apt-get install php5-mysqlnd
// 检查是否安装成功
// php -i | grep Client
// 是否存在 Client API version => mysqlnd ...
//
// 一些注意事项
// 1. mysqlnd驱动设计不支持重新连接
// https://bugs.php.net/bug.php?id=52561
// $this->link->ping();
// 2. 长连接与mysqlib不同
//
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

// define('MYSQLND_HOST', 'p:127.0.0.1');
define('MYSQLND_HOST', '127.0.0.1');
define('MYSQLND_USERNAME', 'root');
define('MYSQLND_PASSWD', '111111');
define('MYSQLND_DBNAME', 'meixiansong');
define('MYSQLND_PORT', 3306);
define('MYSQLND_CHARSET', 'utf8');

class CoMysqlException extends Exception {}
class CoMysql {
    // query计数器，用来做workPool的key
    private $queryId = 0;
    // 是否已经发送pool任务
    private $isSendPoll = false;
    // 闲置连接
    private $idlePool;
    // 工作连接
    // [queryId => link]
    private $workPool = [];


    // 当前query数量，用于判断是否停止poll
    private $queryCount = 0;
    private $scheduler;
    private $ioPollTid = 0;
    public function startPoll() {
        $this->ioPollTid = $this->scheduler->newTask($this->scheduler->ioPollTask());
    }
    public function stopPoll() {
        yield $this->ioPollTid ? Scheduler::syscallKillTask($this->ioPollTid, function() {
            $this->ioPollTid = 0;
        }) : null;
    }

    // 做连接数量闲置
    // private $connectionNum;
    // private $poolSize;
    // private $waitQueue = [];

    public function __construct() {
        $this->idlePool = new SplQueue;
        $this->scheduler = new MysqlScheduler;
        // 填充linkpool
        // for ($i=0; $i < 10; $i++) {
        //     $this->idlePool->enqueue($this->createLink());
        // }
    }

    public function __destruct() {
        // $this->closeAll();
    }

    // mysqli建立的持久链接，必须在close之后，才会下面的代码复用
    public function closeAll() {
        if(!$this->workPool->isEmpty()) {
            $link = $this->workPool->dequeue();
            $link->close();
        }
    }

    /**
     * 创建连接
     * 注意mysql连接数压力
     * @return [type] [description]
     */
    public function createLink() {
        $link = new mysqli(MYSQLND_HOST . ':' . MYSQLND_PORT, MYSQLND_USERNAME, MYSQLND_PASSWD, MYSQLND_DBNAME);
        if (mysqli_connect_errno()) {
            throw new CoMysqlException("Connect failed: %s\n", mysqli_connect_error());
        }
        $link->set_charset(MYSQLND_CHARSET);
        return $link;
    }

    public function getLink() {
        if($this->idlePool->isEmpty()) {
            // 创建连接并加入空闲队列
            $this->idlePool->enqueue($this->createLink());
        }
        $link = $this->idlePool->dequeue();
        // mysqlnd ping无效
        // if(!$link->ping()) {}
        yield Task::retval($link);
    }

    // FIXME
    private function reconnect(&$link) {
        if ($link->errno == 2013 || $link->errno == 2006) {
            $link->close();
            if(!$link->connect()) {
                // FIXME
            }
        } else {

        }
    }

    public function query($sql) {
        // 只要有新的query加入，就需要发送到轮训任务中
        // 最好需要把所有query集中到一起，统一发送
        $this->isSendPoll = false; // 重置pool到未发送状态

        $queryId = $this->queryId++;
        $link = (yield $this->getLink());

        $ret = $link->query($sql, MYSQLI_ASYNC);

        if ($ret === false) {
            // reconnect
            if ($link->errno == 2013 || $link->errno == 2006) {
                $link->close();
                if($link->connect()) {
                    // FIXME
                }
            } else {
                // FIXME
                // echo "server exception. \n";
                // $this->connection_num --;
                // $this->wait_queue[] = array(
                //     'sql'  => $sql,
                //     'callback' => $callback,
                // );
            }
        }

        $this->queryCount++;

        // 一旦有query，且尚未开始poll，则开始poll
        // if($this->ioPollTid === 0) {
        //     $this->startPoll();
        // }

        $this->workPool[$queryId] = $link;
        yield Task::retval($queryId);
    }

    public function poll() {
        foreach($this->workPool as $link) {
            yield MysqlScheduler::syscallWaitForRead($link);
        }

        // ioPoll 结束 kill掉 pollTask
        yield Scheduler::syscallKillTask(2);
    }

    public function fetch($queryId) {
        if(!isset($this->workPool[$queryId])) {
            // FIXME isset()
        }

        // fetch之前必须先把query进行poll
        if($this->isSendPoll === false) {
            $this->poll();
            $this->isSendPoll = true;
        }

        // query都完成时，结束poll
        // if($this->queryCount > 0) {
        //     $this->queryCount--;
        // }
        // query计数控制kill
        // if($this->queryCount === 0) {
            // yield $this->stopPoll();
        // }

        $link = $this->workPool[$queryId];

        if ($result = $link->reap_async_query()) {
            // 从工作队列转移到空闲队列
            $this->idlePool->enqueue($link);
            unset($this->workPool[$queryId]);

            // 有问题，可能killTask两次
            // if(count($this->workPool) === 0) {
            //     yield $this->stopPoll();
            // }

            // FIXME fetch func !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
            yield Task::retval($result->fetch_row());
            if (is_object($result)) {
                $result->free();
            }
        } else {
            throw new CoMysqlException(sprintf("MySQLi Error: %s", $link->error));
        }
    }

    public function run(/*Generator $arg1, Generator ...*/) {
        $tasks = func_get_args();
        foreach($tasks as $task) {
            if(!($task instanceof Generator)) {
                throw new InvalidArgumentException("task must be Generator");
            }
            $this->scheduler->newTask($task);
        }
        $this->scheduler->run();
    }
}



// cost 1sec
function task1(CoMysql $coMysql) {
    $q1 = (yield $coMysql->query('select sleep(1)'));
    $q2 = (yield $coMysql->query('select sleep(1)'));
    $q3 = (yield $coMysql->query('select "link3"'));

    $ret1 = (yield $coMysql->fetch($q1));
    $ret2 = (yield $coMysql->fetch($q2));
    $ret3 = (yield $coMysql->fetch($q3));
    var_dump($ret3);
}

// cost 1sec
function task2(CoMysql $coMysql) {
    $map = [];
    for ($i=0; $i < 10; $i++) {
        $map[$i] = (yield $coMysql->query('select sleep(1)'));
    }
    $qid = (yield $coMysql->query('select "task2"'));

    for ($i=0; $i < 10; $i++) {
        $ret = (yield $coMysql->fetch($map[$i]));
    }

    $ret = (yield $coMysql->fetch($qid));
    var_dump($ret);
}

function task3(CoMysql $coMysql) {
    $map = [];
    for ($i=0; $i < 10; $i++) {
        $map[$i] = (yield $coMysql->query('select sleep(1)'));
    }
    $qid = (yield $coMysql->query('select "task3"'));

    for ($i=0; $i < 10; $i++) {
        $ret = (yield $coMysql->fetch($map[$i]));
    }

    $ret = (yield $coMysql->fetch($qid));
    var_dump($ret);
}

$start = microtime(true);

$coMysql = new CoMysql;
$coMysql->run(task1($coMysql), task2($coMysql), task3($coMysql));

echo microtime(true) - $start . PHP_EOL;

// $start = microtime(true);
// $link = new mysqli(MYSQLND_HOST . ':' . MYSQLND_PORT, MYSQLND_USERNAME, MYSQLND_PASSWD, MYSQLND_DBNAME);
// for ($i=0; $i < 5; $i++) {
//     $link->query('select sleep(1)');
// }
// echo microtime(true) - $start . PHP_EOL;
