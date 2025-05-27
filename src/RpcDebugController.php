<?php
declare(strict_types=1);

namespace Ideepler\HyperfRpcDebug;

use Hyperf\Di\Annotation\AnnotationCollector;
use Hyperf\JsonRpc\Packer\JsonEofPacker;
use Hyperf\JsonRpc\Packer\JsonLengthPacker;
use Hyperf\RpcServer\Annotation\RpcService;

use function Hyperf\Config\config;
use function Hyperf\Support\env;

class RpcDebugController
{
    protected $request;
    protected $response;
    protected $classmap = [];
    public function __construct() {
        $this->request = \Hyperf\Context\ApplicationContext::getContainer()->get(\Hyperf\HttpServer\Contract\RequestInterface::class);
        $this->response = \Hyperf\Context\ApplicationContext::getContainer()->get(\Hyperf\HttpServer\Contract\ResponseInterface::class);
    }
    /**
     * 测试.
     *
     * @return ResponseInterface.
     */
    function debug()
    {
        $this->classmap = [];
        $defaultPort = env('HTTP_PORT', 8081);
        $server = $this->request->input('server', '127.0.0.1');
        $port = $this->request->input('port', $defaultPort);
        $par = trim($this->request->input('fucontionname', "[\n\n]"));
        if ($this->request->getMethod() == 'POST') {
            //请求数据.
            try {
                return $this->rpcRe($this->request->all());
            } catch (\Exception $ex) {
                return $ex->getMessage();
            }
        }
        $this->getClass();
        $servernameoptions = ['<option>...</option>'];
        foreach ($this->classmap as $k => $v) {
            $v['funlistoptions'] = ['<option>...</option>'];
            foreach ($v['funlist'] as $f => $pars) {
                $v['funlistoptions'][] = str_replace([
                    '-parameter-',
                    '-placeholder-'
                ], [implode("<br/>", $pars), $f], '<option parametertip="-parameter-" value="-placeholder-">-placeholder-</option>');
            }
            $v['funlistoptions'] = implode("\n", $v['funlistoptions']);
            $servernameoptions[] = str_replace('-placeholder-', $k, '<option value="-placeholder-">-placeholder-</option>');
            $this->classmap[$k] = $v;
        }
        $servernameoptions = implode("\n", $servernameoptions);
        $classmap = json_encode(array_values($this->classmap));
        $html = <<<html
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<script src="https://libs.baidu.com/jquery/1.9.1/jquery.min.js"></script>
<script type="text/javascript">
var clasmap = $classmap;
$(document).ready(function(){
    $(".button").click(function(){
        $('.res').text('请求中...');
        $.post("",$("#from").serialize(),function(result){
            $('.res').text(result);
        });
    });
    $(".servername").change(function(){
        for (var x in clasmap) {
            if ($(this).val() == clasmap[x].sname) {
                $('.fucontionname').html(clasmap[x].funlistoptions);
                break;
            }
        }
    });
    $('.fucontionname').change(function(){
        $('.parametertip').html($('.fucontionname option:selected').attr('parametertip'));
    })
});
</script>
</head>

<body>
<div style="width:100px;float:left;">
<form id="from" method="get">
  <p>server: <input type="text" value="$server" name="server" /></p>
  <p>port: <input type="text" value="$port" name="port" /></p>
  <p>class: <select class="servername" name='servername'>$servernameoptions</select></p>
  <p>method: <select class='fucontionname' name='fucontionname'></select></p>
  <p style="width: 500px;">参数: <br /><span class='parametertip'></span><textarea rows="10" cols="50" name="par">$par</textarea></p>
  <input class="button" type="button" value="提交" />
</form>
</div>
<div style="float:right; width:800px;" >结果:<textarea style="display:block;min-height:80%;min-width:80%" class="res"></textarea></div>
</body>
</html>
html;
        return $this->response->html($html);
    }


    /**
     * 执行请求
     * @param array $data
     * @return bool|string
     */
    private function rpcRe(array $data)
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (false == socket_connect($socket, $data['server'], $data['port'])) {
            return $this->response->write('链接失败:' . socket_strerror(socket_last_error($socket)));
        }
        $params = json_decode($data['par'], true);
        $data = [
            "jsonrpc" => '2.0',
            'method' => \Hyperf\Context\ApplicationContext::getContainer()->get(\Hyperf\Rpc\PathGenerator\PathGenerator::class)->generate($data['servername'], $data['fucontionname']),
            'params' => $params,
            'id' => uniqid() .'k'. time(),
            'context' => [],
        ];
        if ($this->isLengthCheck()) {
            $packer = \Hyperf\Context\ApplicationContext::getContainer()->get(JsonLengthPacker::class);
        } else {
            $packer = \Hyperf\Context\ApplicationContext::getContainer()->get(JsonEofPacker::class);
        }
        $dat = $packer->pack($data);
        socket_write($socket, $dat, strlen($dat));
        $res = '';
        for ($i=0;$i<=100;++$i) {
            $tmp = socket_read($socket, 64 * 1024);
            $res .= $tmp;
            if (strlen($tmp) < 64 * 1024) {
                break;
            }
        }
        socket_close($socket);
        $res1 = $packer->unpack($res);

        if (is_null($res1)) {
            // 这种有可能是包太大导致不完整了.
            return $res;
        }
        return (json_encode($res1, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        //驼峰命名转下划线命名
    }
    private function getClass($path = null)
    {
        $classs = (AnnotationCollector::getClassesByAnnotation(RpcService::class));
        foreach ($classs as $class => $rpc) {
            $this->parseClass($class, $rpc);
        }
    }

    private function isLengthCheck()
    {
        if ('jsonrpc-tcp-length-check' == env('JSON_RPC_TEST_PROTOCOL')) {
            return true;
        }
        $server = config('server.servers');
        foreach ($server as $v) {
            if (!empty($v['name']) && ($v['name'] == 'jsonrpc') && !empty($v['settings']['open_length_check'])) {
                return true;
            }
        }
        return false;
    }

    private function parseClass($class, RpcService $rpc)
    {
        if (!class_exists($class)) {
            return [];
        }
        $reflect = new \ReflectionClass($class);
        $sername = $rpc->name;
        $methods = $reflect->getMethods(\ReflectionMethod::IS_PUBLIC);
        $this->classmap[$sername] = [
            'sname' => $sername,
            'funlist' => [],
        ];
        foreach ($methods as $method) {
            $name = $method->getName();
            if (in_array($name, ['__construct'])) {
                continue;
            }
            $parameters = $method->getParameters();
            $tmpPar = [
                '<pre>',
                $this->trimDocComment($method->getDocComment()),
                '</pre>',
            ];
            foreach ($parameters as $parameter) {
                $tmpstr = [];
                if ($parameter->getType()) {
                    $tmpstr[] = $parameter->getType();
                    $tmpstr[] = ' ';
                }
                $tmpstr[] = '$';
                $tmpstr[] = $parameter->getName();
                if($parameter->isDefaultValueAvailable()) {
                    $tmpstr[] = '=';
                    if (is_bool($parameter->getDefaultValue())) {
                        $tmpstr[] = $parameter->getDefaultValue() ? 'true' : 'false';
                    } elseif (is_scalar($parameter->getDefaultValue())) {
                        $tmpstr[] = is_string($parameter->getDefaultValue())
                        ? "'".$parameter->getDefaultValue()."'":$parameter->getDefaultValue();
                    } else {
                        $tmpstr[] = json_encode($parameter->getDefaultValue());
                    }
                }
                $tmpPar[] = implode('',$tmpstr);
            }
            if ($method->getReturnType()) {
                try {
                    $tmpPar[] = '@return '.$method->getReturnType()->getName();
                }catch (\Throwable $ex){
                    $tmpPar[] = '@return unknown';
                }
            }
            $this->classmap[$sername]['funlist'][$name] = $tmpPar;
        }
    }

    /**
     * 移除注释中的冗余空格
     * @param string $docComment 内容.
     * @return string
     */
    private function trimDocComment(string $docComment): string
    {
        if(!strpos($docComment,PHP_EOL)){
            return $docComment;
        }

        $docCommentList = explode(PHP_EOL, $docComment);
        foreach ($docCommentList as $key => $content){
            $docCommentList[$key] = ltrim($content);
        }
        return join(PHP_EOL, $docCommentList);
    }
}
