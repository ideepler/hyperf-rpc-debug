<?php

use Hyperf\HttpServer\Router\Router;

Router::addServer('httpRpcDebug', function () {
	Router::addRoute(['GET', 'POST'], '/', [\Ideepler\HyperfRpcDebug\RpcDebugController::class, 'debug']);
});
