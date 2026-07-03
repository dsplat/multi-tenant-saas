<?php

namespace MultiTenantSaas\Services\Ai;

use MultiTenantSaas\Contracts\AiTextServiceContract;
use MultiTenantSaas\Services\Ai\Drivers\AiDriverContract;

/**
 * AI 文本推理服务（AgentRuntime 推理引擎）
 *
 * 编排层：根据 config/ai.php 选择驱动并委托执行，封装失败重试。
 * 通过 AiDriverContract 与具体后端解耦；支持运行时注入驱动实例（供测试注入 Mock 驱动）。
 *
 * 实现非流式接口（chat / complete）与流式接口（streamChat）；流式委托驱动产出 StreamChunk。
 */
class AiTextService implements AiTextServiceContract
{
    /**
     * AI 配置（config/ai.php）
     */
    protected array $config;

    /**
     * 已实例化的驱动（按名称缓存）
     *
     * @var array<string, AiDriverContract>
     */
    protected array $drivers = [];

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('ai', []);
    }

    /**
     * {@inheritDoc}
     */
    public function chat(array $messages, array $options = []): AiResponse
    {
        $driver = $this->driver($options['driver'] ?? null);
        // 驱动已解析，从 options 中移除内部使用的 driver 键，避免随 payload 泄漏到外部 API
        unset($options['driver']);

        return $this->retry(fn () => $driver->chat($messages, $options));
    }

    /**
     * {@inheritDoc}
     */
    public function complete(string $prompt, array $options = []): AiResponse
    {
        $driver = $this->driver($options['driver'] ?? null);
        // 驱动已解析，从 options 中移除内部使用的 driver 键，避免随 payload 泄漏到外部 API
        unset($options['driver']);

        return $this->retry(fn () => $driver->complete($prompt, $options));
    }

    /**
     * 流式对话补全
     *
     * 解析驱动后委托 driver->streamChat，逐块产出 StreamChunk。
     * 流式调用不走重试：Generator 为惰性序列，HTTP 请求在迭代时才发起，
     * 且中途失败重试会导致重复输出，故直接委托。
     *
     * @param  array  $messages  OpenAI 消息结构
     * @param  array  $options  同 chat() 的 $options
     * @return \Generator<int, StreamChunk, mixed, void>
     */
    public function streamChat(array $messages, array $options = []): \Generator
    {
        $driver = $this->driver($options['driver'] ?? null);
        unset($options['driver']);

        yield from $driver->streamChat($messages, $options);
    }

    /**
     * {@inheritDoc}
     *
     * - AiDriverContract 实例：直接返回，跳过名称解析（供测试注入 Mock 驱动）
     * - 字符串：按名称从 config(ai.drivers) 解析，命中后缓存实例
     * - null：使用 config(ai.default)，缺省回退 'mock'
     *
     * 各驱动自行在构造时读取 config('ai')，故此处统一以无参方式实例化，
     * 兼容 MockAiDriver(script) 与 LaravelAiDriverAdapter(config) 两种构造签名。
     */
    public function driver(AiDriverContract|string|null $name = null): AiDriverContract
    {
        // 实例直传：跳过名称解析与缓存（测试注入 Mock 驱动的关键路径）
        if ($name instanceof AiDriverContract) {
            return $name;
        }

        $name = $name ?: (string) ($this->config['default'] ?? 'mock');

        if (isset($this->drivers[$name])) {
            return $this->drivers[$name];
        }

        $class = $this->config['drivers'][$name] ?? null;

        if ($class === null || ! class_exists($class) || ! is_subclass_of($class, AiDriverContract::class)) {
            throw new \RuntimeException("AI driver [{$name}] is not registered in config(ai.drivers).");
        }

        return $this->drivers[$name] = new $class;
    }

    /**
     * 失败重试封装
     *
     * 重试次数与间隔读取自 config(ai.retry)：
     *  - times:    总尝试次数（含首次，>=1）
     *  - sleep_ms: 重试间隔毫秒数
     *
     * @param  callable(): AiResponse  $callback
     *
     * @throws \Throwable 重试耗尽后抛出最后一次异常
     */
    protected function retry(callable $callback): AiResponse
    {
        $times = max(1, (int) ($this->config['retry']['times'] ?? 1));
        $sleepMs = (int) ($this->config['retry']['sleep_ms'] ?? 200);

        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return $callback();
            } catch (\Throwable $e) {
                if ($attempt >= $times) {
                    throw $e;
                }
                usleep($sleepMs * 1000);
            }
        }
    }
}
