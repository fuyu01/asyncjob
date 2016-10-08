<?php
/**
 * Created by PhpStorm.
 * User: yu.fu
 * Date: 2016/8/18
 * Time: 15:47
 */
namespace src;

use src\strconst;

class server {
    protected $taskInfo;
    protected $config = [
        //自定义协议配置
//        'open_length_check' => 1,
//        'package_length_type' => 'N',
//        'package_length_offset' => 0,
//        'package_body_offset' => 4,
//
//        'package_max_length' => 2097152, // 1024 * 1024 * 2,
//        'buffer_output_size' => 3145728, //1024 * 1024 * 3,
//        'pipe_buffer_size' => 33554432, // 1024 * 1024 * 32,

        'open_tcp_nodelay' => 1,

        'backlog' => 3000,
        //'demonsize' => 1 暂时注释需要看server启动的信息
    ];
    protected $server;
    private $serverIP;
    private $serverPort;

    public function __construct($ip = '127.0.0.1', $port = 9501) {
        $this->server = new \swoole_server($ip, $port);


        $this->server->on('connect', array($this, 'onConnect'));
        $this->server->on('start', array($this, 'onStart'));

        //监听数据发送事件
        $this->server->on('receive', array($this, 'onReceive'));

        //监听连接关闭事件
        $this->server->on('close', array($this, 'onClose'));

        $this->server->on('task', array($this, 'onTask'));
        $this->server->on('Finish', array($this, 'onFinish'));


        $this->serverIP = $ip;
        $this->serverPort = $port;
    }

    public function configure(array $config) {
        $this->config = array_merge($this->config, $config);
        return $this;
    }


    final public function onConnect(\swoole_server $server, $fd, $from_id) {
        echo 'client in,from reactor:' . $from_id;
    }

    final public function onStart(\swoole_server $server) {
        echo "MasterPid={$server->master_pid}\n";
        echo "ManagerPid={$server->master_pid}\n";
        echo "Server: start.Swoole version is [" . SWOOLE_VERSION . "]\n";
    }

    final public function onClose(\swoole_server $server, $fd, $from_id) {
        echo 'client disconnect,from reactor:' . $from_id;
    }

    final public function onFinish(\swoole_server $serv, $task_id, $data) {
        $fd = $data["fd"];
        $guid = $data["guid"];

        //判断是否需要返回发送信息给客户端
        if (!isset($this->taskInfo[$fd][$guid])) {
            return true;
        }

        $key = $this->taskInfo[$fd][$guid]["taskkey"][$task_id];

        //save the result
        $this->taskInfo[$fd][$guid]["result"][$key] = $data["result"];

        //remove the used taskid
        unset($this->taskInfo[$fd][$guid]["taskkey"][$task_id]);

        $result = $data['result'];
        switch ($data["type"]) {

            case strconst::MODE_NEEDRESULT_SINGLE:
                $serv->send($fd, json_encode(['code' => '00000', 'msg' => 'onfinish result', "guid" => $guid, "data" => $result, 'is_async' => $data['is_async']]));
                unset($this->taskInfo[$fd][$guid]);
                return true;
                break;
            case strconst::MODE_NEEDRESULT_MULTI:
                if (count($this->taskInfo[$fd][$guid]["taskkey"]) == 0) {
                    $serv->send($fd, json_encode(['code' => '00000', 'msg' => 'onfinish result', "guid" => $guid, "data" => $result, 'is_async' => $data['is_async']]));
                    unset($this->taskInfo[$fd][$guid]);
                }
                return true;
                break;
            default:
                return true;
                break;
        }
    }

    final public function onReceive(\swoole_server $server, $fd, $from_id, $data) {
        //目前只支持json格式
        $rs = json_decode($data, true);

        if (!$rs || !$rs["api"]) {
            //参数错误，则发送错误信息给client，client通过guid判断用户信息
            $server->send($fd, json_encode(['code' => 10001, 'msg' => 'invalid param']));//无result返回全部是server通知
            return true;
        }

        $guid = $rs['guid'];//消息的唯一标示，防止重复

        $task = array(
            "type" => $rs["type"],
            "guid" => $guid,
            "fd" => $fd,
            "protocol" => "tcp",
        );

        //server 端默认异步投递task方式
        switch ($rs['type']) {
            case strconst::MODE_NEEDRESULT_SINGLE :
                $task['api'] = $rs['api'];
                $task['is_async'] = $rs['is_async'];
                $taskid = $server->task($task);
                $this->taskInfo[$fd][$guid]["taskkey"][$taskid] = "one";

                if ($rs['is_async']) {
                    //异步需要先回复投递成功
                    $server->send($fd, json_encode(['code' => '10000', 'msg' => 'send task succ', "guid" => $task["guid"]]));
                }
                return true;
                break;

            case strconst::MODE_NORESULT_SINGLE :
                $task['api'] = $rs['api'];
                $server->task($task);

                $server->send($fd, json_encode(['code' => '10000', 'msg' => 'send task succ', "guid" => $task["guid"]]));
                return true;
                break;

            case strconst::MODE_NEEDRESULT_MULTI :
                foreach ($rs["api"] as $k => $v) {
                    $task["api"] = $v;
                    $taskid = $server->task($task);
                    $this->taskInfo[$fd][$guid]["taskkey"][$taskid] = $k;
                }

                if ($rs['is_async']) {
                    //异步需要先回复投递成功
                    $server->send($fd, json_encode(['code' => '10000', 'msg' => 'send task succ', "guid" => $task["guid"]]));
                }
                return true;
                break;

            case strconst::MODE_NORESULT_MULTI :
                foreach ($rs["api"] as $k => $v) {
                    $task["api"] = $v;
                    $server->task($task);
                }
                $server->send($fd, json_encode(['code' => '10000', 'msg' => 'send task succ', "guid" => $task["guid"]]));
                return true;
                break;
        }
    }

    final public function onTask(\swoole_server $serv, $task_id, $from_id, $data) {
        //执行用户自定义方法得到结果放入result返回给worker进程进而触发onfinish
        try {
            $data['result'] = $this->doWork($data);
        } catch (Exception $e) {
            $data['result'] = $e->getMessage();
        }

        return $data;//返回work进程并触发onfinish方法
    }

    protected function doWork($data) {
        //测试,使用者在子类自行重写此方
        return $data;
    }

    public function start() {
        $this->server->set($this->config);
        $this->server->start();
    }
}
