<?php
/**
 * Created by PhpStorm.
 * User: yu.fu
 * Date: 2016/9/12
 * Time: 11:22
 */
namespace src;
class client {
    private $clientObj;

    //连接配置 ip port
    //['ip'=>'127.0.0.1','port'=>9501]默认
    private $clientConfig;

    private static $asyncs = array();

    private static $asyncrs = array();

    private $guid;

    //实例化client并连接，成功返回client实例，失败返回errcode -1
    public function __construct($clientConfig) {
        if (!$clientConfig) {
            echo 'invalid config';
            exit;
        }
        $this->clientConfig = $clientConfig;
    }

    private function generateGuid() {
        while (1) {
            $guid = md5(microtime(true) . mt_rand(1, 1000000) . mt_rand(1, 1000000));
            //防止async出现重复，如果重复则async会认为还有result没有返回则会一直等待导致无法返回结果
            //同步请求不存在此问题
            if (!isset(self::$asyncs[$guid])) {
                return $guid;
            }
        }
    }

    public function singleRequest($name, $param, $mode = strconst::MODE_NEEDRESULT_SINGLE, $is_async = true) {
        $this->guid = $this->generateGuid();

        $postdata = array(
            'guid' => $this->guid,
            'api' => array(
                "one" => array(
                    'name' => $name,
                    'param' => $param,
                )
            ),
            'is_async' => $is_async,
            'type' => $mode
        );

        $result = $this->doRequest(json_encode($postdata), $postdata["type"], $is_async);


        if ($this->guid != $result["guid"]) {
            return ['code' => 10003, 'msg' => 'request wrong'];
        }

        return $result;
    }

    private function doRequest($str, $type, $is_async) {
        try {
            $client = $this->getInstance();
        } catch (\Exception $e) {
            $data = ["guid" => $this->guid, 'code' => 10004, 'msg' => 'wrong client'];
            return $data;
        }

        $ret = $client->send($str);

        //ok fail
        if (!$ret) {
            $errorcode = $client->errCode;

            //destroy error client obj to make reconncet
            unset($this->clientObj);

            if ($errorcode == 0) {
                $msg = "connect fail.check host dns.";
                $errorcode = -1;
            } else {
                $msg = \socket_strerror($errorcode);
            }
            $packet = ['code' => $errorcode, 'msg' => $msg];

            return $packet;
        }

        if ($is_async) {
            self::$asyncs[$this->guid] = $client;
        }

        //recive the response
        $data = $this->waitResult($client);
        $data["guid"] = $this->guid;
        return $data;


    }

    private function waitResult($client) {
        while (1) {
            $result = $client->recv();

            if ($result !== false && $result != "") {
                $data = json_decode($result);
                if ($data["is_async"]) {
                    // async complete result
                    if (isset(self::$asyncs[$data["data"]["guid"]])) {

                        //remove the guid on the asynclist
                        unset(self::$asyncs[$data["data"]["guid"]]);

                        //add result to async result
                        self::$asyncrs[$data["data"]["guid"]] = $data["data"];
                        self::$asyncrs[$data["data"]["guid"]]["fromwait"] = 1;
                    } else {
                        //not in the asynclist drop this packet
                        continue;
                    }
                } else {
                    //同步返回的信息，包括通知和执行数据
                    return $data;
                }
            } else {
                //time out
                $packet = ['code' => '100009', 'msg' => 'the recive wrong or timeout'];
                $packet["guid"] = $this->guid;
                return $packet;
            }
        }
    }

    public function getAsyncData() {
        while (1) {

            if (count(self::$asyncs) > 0) {
                foreach (self::$asyncs as $k => $client) {
                    if ($client->isConnected()) {
                        $data = $client->recv();
                        if ($data !== false && $data != "") {
                            $data = json_decode($data);

                            if (isset(self::$asyncs[$data["data"]["guid"]]) && $data["is_async"]) {

                                //ok recive an async result
                                //remove the guid on the asynclist
                                unset(self::$asyncs[$data["data"]["guid"]]);

                                //add result to async result
                                self::$asyncrs[$data["data"]["guid"]] = $data["data"];
                                self::$asyncrs[$data["data"]["guid"]]["fromwait"] = 0;
                                continue;
                            } else {
                                //not in the asynclist drop this packet
                                continue;
                            }
                        } else {
                            //remove the result
                            unset(self::$asyncs[$k]);
                            self::$asyncrs[$k] = ['msg' => "the recive wrong or timeout", 'code' => 100009];
                            continue;
                        }
                    } else {
                        //remove the result
                        unset(self::$asyncs[$k]);
                        self::$asyncrs[$k] = ['msg' => "Get Async Result Fail: Client Closed.", 'code' => 100012];
                        continue;
                    }
                } // foreach the list
            } else {
                break;
            }
        }//while

        $result = self::$asyncrs;
        self::$asyncrs = array();
        return ['msg' => "OK", 'code' => 0, 'data' => $result];
    }

    public function getInstance() {
        $config = $this->clientConfig;

        //此处使用同步模式，异步模式只能在cli下使用
        if (!isset($this->clientObj[$config['ip']][$config['port']])) {
            $client = new \swoole_client(SWOOLE_SOCK_TCP | SWOOLE_KEEP);

            if (!$this->clientObj[$config['ip']][$config['port']]->connect($config['ip'], $config['port'])) {
                $errorCode = $client->errCode;
                if ($errorCode == 0) {
                    $msg = "connect fail.";
                    $errorCode = -1;
                } else {
                    $msg = \socket_strerror($errorCode);
                }
                throw new \Exception($msg, $errorCode);
            }
            $this->clientObj[$config['ip']][$config['port']] = $client;
        }

        return $this->clientObj[$config['ip']][$config['port']];

    }

    //用于消除长时间的连接
    public function delClient($ip, $port) {
        unset($this->clientObj[$ip][$port]);
    }



    //TODO::后续加入别的连接方式
//    public function getConnectMode(){
//
//    }


}