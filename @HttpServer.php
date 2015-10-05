<?php
spl_autoload_register(function($className) {
	$map = [
		'Task' 				=>	__DIR__ . '/Scheduler/Task.php',
		'Scheduler' 		=>	__DIR__ . '/Scheduler/Scheduler.php',
		'SystemCall' 		=>	__DIR__ . '/Scheduler/SystemCall.php',
		'StreamScheduler'	=>	__DIR__ . '/Scheduler/StreamScheduler.php',
        'MysqlScheduler'    =>  __DIR__ . '/Scheduler/MysqlScheduler.php',
	];
	if(isset($map[$className])) {
		require_once $map[$className];
	}
}, true);


class CoSocket {
    protected $socket;

    public function __construct($socket) {
        $this->socket = $socket;
    }

    public function accept() {
        yield StreamScheduler::syscallWaitForRead($this->socket);
        yield Task::retval(new CoSocket(stream_socket_accept($this->socket, 0)));
    }

    public function read($size) {
        yield StreamScheduler::syscallWaitForRead($this->socket);
        yield Task::retval(fread($this->socket, $size));
    }

    public function write($string) {
        yield StreamScheduler::syscallWaitForWrite($this->socket);
        fwrite($this->socket, $string);
    }

    public function close() {
        @fclose($this->socket);
    }
}

function server($port = 80) {
    echo "Starting server at port $port...\n";

    $socket = @stream_socket_server("tcp://127.0.0.1:$port", $errNo, $errStr);
    if (!$socket) throw new Exception($errStr, $errNo);

    stream_set_blocking($socket, 0);

    $socket = new CoSocket($socket);
    while (true) {
        yield StreamScheduler::syscallNewTask(
            handleClient(yield $socket->accept())
        );
    }
}

function handleClient($socket) {
    $data = (yield $socket->read(8192));

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

    yield $socket->write($response);
    yield $socket->close();
}

$scheduler = new StreamScheduler;
$scheduler->newTask(server());
$scheduler->run();
