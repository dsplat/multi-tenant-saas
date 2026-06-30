<?php

namespace MultiTenantSaas\Contracts;

/**
 * 事件处理器接口契约
 *
 * 内部事件订阅者需实现此接口，由 EventBusService 在事件分发时
 * 实例化处理器并调用 handle() 完成事件处理。
 */
interface EventHandler
{
    /**
     * 处理事件
     *
     * @param  string  $eventType  事件类型
     * @param  array<string, mixed>  $payload  事件数据
     */
    public function handle(string $eventType, array $payload): void;
}
