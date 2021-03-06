<?php

/**
 * @version     1.0.0-dev
 * @package     WebChannel Client
 * @link        https://localzet.gitbook.io
 * 
 * @author      localzet <creator@localzet.ru>
 * 
 * @copyright   Copyright (c) 2018-2020 Zorin Projects 
 * @copyright   Copyright (c) 2020-2022 NONA Team
 * 
 * @license     https://www.localzet.ru/license GNU GPLv3 License
 */

namespace localzet\Channel;

use localzet\Core\Connection\AsyncTcpConnection;
use localzet\Core\Timer;
use localzet\Core\Protocols\Frame;

/**
 * Channel/Client
 * @version 1.0.7
 */
class Client
{
    /**
     * @var callable
     */
    public static $onMessage = null;

    /**
     * @var callable
     */
    public static $onConnect = null;

    /**
     * @var callable
     */
    public static $onClose = null;

    /**
     * @var \localzet\Core\Connection\TcpConnection
     */
    protected static $_remoteConnection = null;

    /**
     * @var string
     */
    protected static $_remoteIp = null;

    /**
     * @var int
     */
    protected static $_remotePort = null;

    /**
     * @var Timer
     */
    protected static $_reconnectTimer = null;

    /**
     * @var Timer
     */
    protected static $_pingTimer = null;

    /**
     * @var array
     */
    protected static $_events = array();

    /**
     * @var callable
     */
    protected static $_queues = array();

    /**
     * @var bool
     */
    protected static $_isCoreEnv = true;

    /**
     * @var int
     */
    public static $pingInterval = 25;

    /**
     * @param string $ip
     * @param int $port
     */
    public static function connect($ip = '127.0.0.1', $port = 2206)
    {
        if (self::$_remoteConnection) {
            return;
        }

        self::$_remoteIp = $ip;
        self::$_remotePort = $port;

        if (PHP_SAPI !== 'cli' || !class_exists('localzet\Core\Server', false)) {
            self::$_isCoreEnv = false;
        }

        if (self::$_isCoreEnv) {
            if (strpos($ip, 'unix://') === false) {
                $conn = new AsyncTcpConnection('frame://' . self::$_remoteIp . ':' . self::$_remotePort);
            } else {
                $conn = new AsyncTcpConnection($ip);
                $conn->protocol = Frame::class;
            }

            $conn->onClose = [self::class, 'onRemoteClose'];
            $conn->onConnect = [self::class, 'onRemoteConnect'];
            $conn->onMessage = [self::class, 'onRemoteMessage'];
            $conn->connect();

            if (empty(self::$_pingTimer)) {
                self::$_pingTimer = Timer::add(self::$pingInterval, 'localzet\Channel\Client::ping');
            }
        } else {
            $remote = strpos($ip, 'unix://') === false ? 'tcp://' . self::$_remoteIp . ':' . self::$_remotePort : $ip;
            $conn = stream_socket_client($remote, $code, $message, 5);
            if (!$conn) {
                throw new \Exception($message);
            }
        }

        self::$_remoteConnection = $conn;
    }

    /**
     * @param \localzet\Core\Connection\TcpConnection $connection
     * @param string $data
     * @throws \Exception
     */
    public static function onRemoteMessage($connection, $data)
    {
        $data = unserialize($data);
        $type = $data['type'];
        $event = $data['channel'];
        $event_data = $data['data'];

        $callback = null;

        if ($type == 'event') {
            if (!empty(self::$_events[$event])) {
                call_user_func(self::$_events[$event], $event_data);
            } elseif (!empty(Client::$onMessage)) {
                call_user_func(Client::$onMessage, $event, $event_data);
            } else {
                throw new \Exception("event:$event ???? ???????????????? ????????????????");
            }
        } else {
            if (isset(self::$_queues[$event])) {
                call_user_func(self::$_queues[$event], $event_data);
            } else {
                throw new \Exception("queue:$event ???? ???????????????? ????????????????");
            }
        }
    }

    /**
     * @return void
     */
    public static function ping()
    {
        if (self::$_remoteConnection) {
            self::$_remoteConnection->send('');
        }
    }

    /**
     * @return void
     */
    public static function onRemoteClose()
    {
        echo "???????????????????????????? ????????????: ???????????????????? ??????????????, ?????????????? ??????????????????????????????\n";
        self::$_remoteConnection = null;
        self::clearTimer();
        self::$_reconnectTimer = Timer::add(1, 'localzet\Channel\Client::connect', array(self::$_remoteIp, self::$_remotePort));
        if (self::$onClose) {
            call_user_func(Client::$onClose);
        }
    }

    /**
     * @return void
     */
    public static function onRemoteConnect()
    {
        $all_event_names = array_keys(self::$_events);
        if ($all_event_names) {
            self::subscribe($all_event_names);
        }
        self::clearTimer();

        if (self::$onConnect) {
            call_user_func(Client::$onConnect);
        }
    }

    /**
     * @return void
     */
    public static function clearTimer()
    {
        if (!self::$_isCoreEnv) {
            throw new \Exception('localzet\\Channel\\Client ???? ???????????????????????? ?????????? clearTimer ?????? WebCore.');
        }
        if (self::$_reconnectTimer) {
            Timer::del(self::$_reconnectTimer);
            self::$_reconnectTimer = null;
        }
    }

    /**
     * @param string $event
     * @param callback $callback
     * @throws \Exception
     */
    public static function on($event, $callback)
    {
        if (!is_callable($callback)) {
            throw new \Exception('Callback ???? ?????????????????? ???????????? ?????? ??????????????.');
        }
        self::$_events[$event] = $callback;
        self::subscribe($event);
    }

    /**
     * @param string|string[] $events
     * @return void
     */
    public static function subscribe($events)
    {
        $events = (array)$events;
        self::send(array('type' => 'subscribe', 'channels' => $events));
        foreach ($events as $event) {
            if (!isset(self::$_events[$event])) {
                self::$_events[$event] = null;
            }
        }
    }

    /**
     * @param string|string[] $events
     * @return void
     */
    public static function unsubscribe($events)
    {
        $events = (array)$events;
        self::send(array('type' => 'unsubscribe', 'channels' => $events));
        foreach ($events as $event) {
            unset(self::$_events[$event]);
        }
    }

    /**
     * @param string|string[] $events
     * @param mixed $data
     */
    public static function publish($events, $data)
    {
        self::sendAnyway(array('type' => 'publish', 'channels' => (array)$events, 'data' => $data));
    }

    /**
     * @param string|array $channels
     * @param callable $callback
     * @param boolean $autoReserve
     * @throws \Exception
     */
    public static function watch($channels, $callback, $autoReserve = true)
    {
        if (!is_callable($callback)) {
            throw new \Exception('Callback ???? ?????????????????? ???????????? ?????? ????????????????????.');
        }

        if ($autoReserve) {
            $callback = static function ($data) use ($callback) {
                try {
                    call_user_func($callback, $data);
                } catch (\Exception $e) {
                    throw $e;
                } catch (\Error $e) {
                    throw $e;
                } finally {
                    self::reserve();
                }
            };
        }

        $channels = (array)$channels;
        self::send(array('type' => 'watch', 'channels' => $channels));

        foreach ($channels as $channel) {
            self::$_queues[$channel] = $callback;
        }

        if ($autoReserve) {
            self::reserve();
        }
    }

    /**
     * @param string|string[] $channels
     * @throws \Exception
     */
    public static function unwatch($channels)
    {
        $channels = (array)$channels;
        self::send(array('type' => 'unwatch', 'channels' => $channels));
        foreach ($channels as $channel) {
            if (isset(self::$_queues[$channel])) {
                unset(self::$_queues[$channel]);
            }
        }
    }

    /**
     * @param string|string[] $channels
     * @param mixed $data
     * @throws \Exception
     */
    public static function enqueue($channels, $data)
    {
        self::sendAnyway(array('type' => 'enqueue', 'channels' => (array)$channels, 'data' => $data));
    }

    /**
     * @throws \Exception
     */
    public static function reserve()
    {
        self::send(array('type' => 'reserve'));
    }

    /**
     * @param $data
     * @throws \Exception
     */
    protected static function send($data)
    {
        if (!self::$_isCoreEnv) {
            throw new \Exception("localzet\\Channel\\Client ???? ???????????????????????? ?????????? {$data['type']} ?????? WebCore.");
        }
        self::connect(self::$_remoteIp, self::$_remotePort);
        self::$_remoteConnection->send(serialize($data));
    }

    /**
     * @param $data
     * @throws \Exception
     */
    protected static function sendAnyway($data)
    {
        self::connect(self::$_remoteIp, self::$_remotePort);
        $body = serialize($data);
        if (self::$_isCoreEnv) {
            self::$_remoteConnection->send($body);
        } else {
            $buffer = pack('N', 4 + strlen($body)) . $body;
            fwrite(self::$_remoteConnection, $buffer);
        }
    }
}
