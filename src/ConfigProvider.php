<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace Ideepler\HyperfRpcDebug;

use Hyperf\Server\Event;
use function Hyperf\Support\env;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
	            'HttpRpcDebug' => \Hyperf\HttpServer\Server::class,
            ],
            'commands' => [
            ],
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],
	        'server' => [
		        'servers' => [
			        [
				        'name' => 'httpRpcDebug',
				        'type' => 1,
				        'host' => '0.0.0.0',
				        'port' => intval(env('RPC_DEBUG_PORT', 9091)),
				        'sock_type' => 1,
				        'callbacks' => [
					        Event::ON_REQUEST => ['HttpRpcDebug', 'onRequest'],
				        ],
				        'settings' => [
					        'daemonize' => env('DAEMONIZE', true),
				        ],
			        ],
		        ]
	        ],
	        'publish' => [
		        [
			        'id' => 'config-routes-debug',
			        'description' => 'rcp debug routes.', // 描述
			        // 建议默认配置放在 publish 文件夹中，文件命名和组件名称相同
			        'source' => __DIR__ . '/../publish/routes.php',  // 对应的配置文件路径
			        'destination' => BASE_PATH . '/config/routes/debug.php', // 复制为这个路径下的该文件
		        ],
	        ],
        ];
    }
}
