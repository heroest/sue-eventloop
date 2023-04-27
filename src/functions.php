<?php

namespace Sue\EventLoop;

use Closure;
use ReflectionFunction;
use React\EventLoop\Factory;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Promise\Promise;
use Sue\EventLoop\Exceptions\PromiseCancelledException;

/**
 * 获取eventloop唯一实例
 *
 * @return \React\EventLoop\LoopInterface
 * ```
 */
function loop()
{
    static $loop = null;
    if (null === $loop) {
        $loop = Factory::create();
        Loop::set($loop);
    }
    return $loop;
}

/**
 * 添加一个一次性timer
 *
 * @param float $interval 延迟时间（秒）
 * @param callable $callback 回调方法
 * @param mixed ...$params 参数
 * @return TimerInterface
 */
function setTimeout($interval, callable $callback, ...$params)
{
    $interval = (float) $interval;

    return loop()->addTimer(
        $interval,
        function () use ($callback, $params) {
            try {
                call($callback, ...$params);
            } catch (\Exception $e) {
            }
        }
    );
}

/**
 * 添加一个反复执行的timer
 *
 * @param float $interval 延迟时间（秒）
 * @param callable $callback 回调方法
 * @param mixed ...$params 回调方法需要的参数，最后一个参数是\React\EventLoop\TimerInterface
 * @return TimerInterface
 */
function setInterval($interval, callable $callback, ...$params)
{
    $interval = (float) $interval;

    return loop()->addPeriodicTimer(
        $interval,
        function (TimerInterface $timer) use ($callback, $params) {
            try {
                $params[] = $timer;
                call($callback, ...$params);
            } catch (\Exception $e) {
                cancelTimer($timer);
            }
        }
    );
}

/**
 * 解除一个timer
 *
 * @param \React\EventLoop\TimerInterface $timer
 * @return void
 */
function cancelTimer(TimerInterface $timer)
{
    loop()->cancelTimer($timer);
}

/**
 * 注册一个下一次eventloop tick时执行的timer
 *
 * @param callable $callback
 * @param mixed ...$params
 * @return Promise|PromiseInterface
 */
function nextTick(callable $callback, ...$params)
{
    $loop = loop();
    $runnable = true;
    $deferred = new Deferred(function ($_, $reject) use (&$runnable) {
        $runnable = false;
        $reject(new PromiseCancelledException('nextTick() was cancelled'));
    });
    $callback = function () use ($deferred, $callback, $params, &$runnable) {
        if ($runnable) {
            try {
                $deferred->resolve(call($callback, ...$params));
            } catch (\Exception $e) {
                $deferred->reject($e);
            }
        }
    };
    $loop->futureTick($callback);
    return $deferred->promise();
}

/**
 * 节流
 *
 * @param string $id
 * @param float $timeout 延迟时间（秒）
 * @param callable $callable
 * @return Promise|PromiseInterface
 */
function throttleById($id, $timeout, callable $callable)
{
    /** @var \React\Promise\Promise[] $promises */
    static $promises = [];

    $id = (string) $id;
    $timeout = (float) $timeout;

    if (isset($promises[$id])) {
        return $promises[$id];
    }

    $deferred = new \React\Promise\Deferred(function ($_, $reject) use (&$promises, $id) {
        unset($promises[$id]);
        $reject(new PromiseCancelledException("throttleById() was cancelled"));
    });
    $runnable = function ($id, callable $callable) use (&$promises, $deferred) {
        unset($promises[$id]);
        try {
            $deferred->resolve(call($callable));
        } catch (\Exception $e) {
            $deferred->reject($e);
        }
    };

    $timer = setTimeout($timeout, $runnable, $id, $callable);
    /** @var Promise $promise */
    $promise = $deferred->promise();
    $promise->always(function () use ($timer) {
        cancelTimer($timer);
    });
    return $promises[$id] = $promise;
}

/**
 * 节流 (在N秒内的相同操作会只会执行一次)
 *
 * @param float $timeout 延迟时间
 * @param callable $callable
 * @return Promise|PromiseInterface
 */
function throttle($timeout, callable $callable)
{
    $id = fetchCallableUniqueId($callable);
    return throttleById($id, $timeout, $callable);
}

/**
 * 根据id防抖
 *
 * @param string $id
 * @param float $timeout
 * @param callable $callable
 * @return Promise|PromiseInterface
 */
function debounceById($id, $timeout, callable $callable)
{
    static $storage = [];

    $id = (string) $id;
    $timeout = (float) $timeout;

    if (isset($storage[$id])) {
        list($deferred, $timer) = $storage[$id];
        cancelTimer($timer);
    } else {
        $deferred = new \React\Promise\Deferred(function ($_, $reject) use (&$storage, $id) {
            unset($storage[$id]);
            $reject(new PromiseCancelledException("debounceById() was cancelled"));
        });
    }

    $runnable = function ($id, callable $callable) use (&$storage, $deferred) {
        unset($storage[$id]);
        try {
            $deferred->resolve(call($callable));
        } catch (\Exception $e) {
            $deferred->reject($e);
        }
    };
    $timer = setTimeout($timeout, $runnable, $id, $callable);
    $promise = $deferred->promise();
    $promise->always(function () use ($timer) {
        cancelTimer($timer);
    });
    $storage[$id] = [$deferred, $timer];
    return $promise;
}

/**
 * 防抖（在N秒内每次相同的请求会重新延迟N秒后再执行）
 *
 * @param float $timeout 延迟时间（秒）
 * @param callable $callable
 * @return Promise|PromiseInterface
 */
function debounce($timeout, callable $callable)
{
    $id = fetchCallableUniqueId($callable);
    return debounceById($id, $timeout, $callable);
}

/**
 * 执行方法（封装ErrorHandler)
 *
 * @param callable $callable
 * @param mixed ...$params
 * @return mixed
 */
function call(callable $callable, ...$params)
{
    static $stacks = [];
    $stacks or set_error_handler(function ($error_no, $error_str) {
        throw new \ErrorException($error_str, $error_no, E_USER_ERROR);
    });
    $stacks[] = true;

    try {
        return call_user_func_array($callable, $params);
    } catch (\Exception $e) {
        throw $e;
    } finally {
        array_pop($stacks);
        $stacks or restore_error_handler();
    }
}

/**
 * 解析callable在程序中的唯一id(长度64 hash)
 *
 * @param callable $callable
 * @return string
 */
function fetchCallableUniqueId(callable $callable)
{
    switch (true) {
        case $callable instanceof Closure:
            $ref = new ReflectionFunction($callable);
            $items = [
                $ref->getFileName(),
                $ref->getStartLine(),
                $ref->getEndLine()
            ];
            return md5(implode('|', $items));

        case is_string($callable):
            /** @var string $callable */
            return md5($callable);

        case is_array($callable):
            list($obj, $method) = $callable;
            if (is_object($obj)) {
                $items = [get_class($obj), spl_object_hash($obj), $method];
            } else {
                $items = [strval($obj), $method];
            }
            return md5(implode('@', $items));

        default:
            return md5(uniqid(microtime(true), true));
    }
}
