<?php
// @see http://www.laruence.com/2015/05/28/3038.html

spl_autoload_register(function($className) {
	$map = [
		'Task' 				=>	__DIR__ . '/Task.php',
		'Scheduler' 		=>	__DIR__ . '/Scheduler.php',
		'SystemCall' 		=>	__DIR__ . '/SystemCall.php',
        'StreamScheduler'   =>  __DIR__ . '/StreamScheduler.php',
		'MysqlScheduler'	=>	__DIR__ . '/MysqlScheduler.php',
	];
	if(isset($map[$className])) {
		require_once $map[$className];
	}
}, true);

// stackedCoroutineTest
///*
function a() {
    yield b();
}
function b() {
    yield c();
    yield d(__FUNCTION__);
}
function c() {
    yield [1, 2, 3];
}
function d($f) {
    yield Task::plainval($f);
}
function e() {
    yield d(__FUNCTION__);
    throw new Exception('test exception');
    yield a();
    yield b();
    yield c();
}
foreach (Task::stackedCoroutine(a()) as $key => $value) {
    var_dump($value);
}

foreach (Task::stackedCoroutine(e()) as $key => $value) {
    var_dump($value);
}
exit;
//*/

/*
$sched = new Scheduler();
$sched->newTask((function() {
	for ($i=0; $i < 5; $i++) {
		echo "X :$i \n";
		yield;
	}
})());
$sched->newTask((function() {
	for ($i=0; $i < 10; $i++) {
		echo "Y :$i \n";
		yield;
	}
})());
$sched->run();
*/

/*
function childTask() {
    $tid = (yield Scheduler::syscallGetTaskId());
    while (true) {
        echo "Child task $tid still alive!\n";
        yield;
    }
}

function task() {
    $tid = (yield Scheduler::syscallGetTaskId());
    $childTid = (yield Scheduler::syscallNewTask(childTask()));

    for ($i = 1; $i <= 6; ++$i) {
        echo "Parent task $tid iteration $i.\n";
        yield;

        if ($i == 3) yield Scheduler::syscallKillTask($childTid);
    }
}

$scheduler = new Scheduler;
$scheduler->newTask(task());
$scheduler->run();
//*/

/*
function server($port = 80) {
    echo "Starting server at port $port...\n";

    $socket = @stream_socket_server("tcp://127.0.0.1:$port", $errNo, $errStr);
    if (!$socket) throw new Exception($errStr, $errNo);

    stream_set_blocking($socket, 0);

    while (true) {
        yield StreamScheduler::syscallWaitForRead($socket);
        $clientSocket = stream_socket_accept($socket, 0);
        yield StreamScheduler::syscallNewTask(handleClient($clientSocket));
    }
}

function handleClient($socket) {
    yield StreamScheduler::syscallWaitForRead($socket);
    $data = fread($socket, 8192);

    $msg = "Received following request:\n\n$data";
    $msgLength = strlen($msg);

    $response = <<<RES
HTTP/1.1 200 OK\r
Content-Type: text/plain\r
Content-Length: $msgLength\r
Connection: close\r
\r
$msg
RES;

    yield StreamScheduler::syscallwaitForWrite($socket);
    fwrite($socket, $response);

    fclose($socket);
}

$scheduler = new StreamScheduler;
$scheduler->newTask(server());
$scheduler->run();
//*/
