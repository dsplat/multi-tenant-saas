<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Ai;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Logging\Services\AuditService;

/**
 * AI 内容安全守护（安全狗）
 *
 * 用户输入进入 LLM 之前的第一道闸：本地轻量正则/关键词扫描（O(n)，
 * 无 LLM 调用、无网络往返），命中即礼貌拒绝，不进后续链路。
 *
 * 分层定位（防误伤）：
 *  - 超范围破坏指令（删库/清空系统/执行系统命令）：系统本无对应工具
 *    （系统命令能力归零铁律），守护层直接拒绝；
 *  - 范围内敏感操作（给客户加积分/调余额等）：属合法业务工具，
 *    不在此拦截，由 L2 确认卡片闸门把关；
 *  - 输出侧不自建过滤：依赖提供商内容安全 + 秘书提示词红线。
 *
 * 可用性铁律：守护自身异常时记录告警并放行，不得把业务链路炸断；
 * 命中拦截时写审计（服务端框架路径，非工具层，不违反审计不可碰铁律）。
 */
class ContentGuardService
{
    /**
     * 内置拦截规则（归一化后的小写无空白文本上匹配）
     *
     * 保持保守：只拦「系统确定不该做」的内容；业务词（群发短信、
     * 发优惠券、给客户加积分、删除标签等）绝不命中。
     */
    private const BUILTIN_PATTERNS = [
        // ── 系统命令/shell 执行诱导（能力归零铁律：即使只读命令也无此能力）──
        // 注：归一化已去空白，量词一律用 \s* 兼容有无空白两种形态
        'system_command' => [
            '/rm-?r-?f/',                              // rm -rf 及其变体（rm -r -f 归一化后为 rm-r-f）
            '/rm-r-f/',
            '/\/bin\/(ba|z|da)?sh\b/',                 // /bin/sh、/bin/bash 等
            '/(curl|wget).{0,40}\|\s*(ba|z)?sh/',      // curl ... | sh 管道执行
            '/\/dev\/tcp\//',                          // bash 反弹 shell 特征
            '/\bnc\s*-[a-z]*e/',                       // netcat -e 反弹
            '/reverse\s*shell|反弹\s*shell/u',
            '/\bmkfs\b|\bdd\s*if=.{0,30}of=\/dev\//',  // 磁盘破坏
            '/\bshutdown\b|\breboot\b|\bkill\s*-9\s*1\b/',
        ],
        // ── SQL 破坏诱导（DELETE FROM/DROP/TRUNCATE 等直接执行语义）──
        // 注：归一化后连写（droptableagents），尾部 \b 会失效，只保留首部词边界
        'sql_destructive' => [
            '/\bdrop\s*(table|database)/',
            '/\btruncate\s*table/',
            '/\bdelete\s*from\s*\w+/',
        ],
        // ── 代码执行诱导（eval/exec/system 等 PHP 动态执行）──
        'code_execution' => [
            '/\beval\s*\(/',
            '/\b(exec|system|passthru|shell_exec|popen|proc_open|pcntl_exec)\s*\(/',
            '/assert\s*\(\s*\$_(get|post|request)/',
        ],
        // ── 超范围破坏诉求（删除/清空 数据库/系统/所有数据，双向语序：删库/库删了）──
        'destructive_business' => [
            '/(删除|清空|抹掉|格式化|销毁).{0,8}(数据库|数据表|系统|服务器|所有数据|全部数据)/u',
            '/(数据库|数据表|系统|服务器|所有数据|全部数据).{0,8}(删了|删掉|删光|清空掉|抹掉|格式化|销毁|清除)/u',
        ],
        // ── 违法违规基础词（政治/色情等深度过滤交给提供商内容安全，此处仅保底）──
        'illegal' => [
            '/(制作|购买|出售).{0,6}(枪支|军火|炸药|毒品)/u',
        ],
    ];

    public function __construct(private readonly ?AuditService $audit = null) {}

    /**
     * 校验用户输入
     *
     * @return array{allowed: bool, category: ?string, message: ?string}
     */
    public function check(string $input): array
    {
        if (! config('ai.content_guard.enabled', true)) {
            return ['allowed' => true, 'category' => null, 'message' => null];
        }

        $text = trim($input);
        if ($text === '') {
            return ['allowed' => true, 'category' => null, 'message' => null];
        }

        try {
            $normalized = $this->normalize($text);
            $category = $this->match($normalized);

            if ($category !== null) {
                $this->recordBlock($category, mb_substr($text, 0, 200));

                return [
                    'allowed' => false,
                    'category' => $category,
                    'message' => '抱歉，这类请求超出了我的能力范围。我是系统的 AI 小助手，只能帮你完成系统内的业务操作（营销策划、客户管理、消息触达等）。有其他业务需要随时告诉我。',
                ];
            }

            return ['allowed' => true, 'category' => null, 'message' => null];
        } catch (\Throwable $e) {
            // 可用性铁律：守护自身故障不炸业务链路，记录告警后放行
            Log::warning('[content-guard] check failed, degrade to allow: '.$e->getMessage());

            return ['allowed' => true, 'category' => null, 'message' => null];
        }
    }

    /**
     * 归一化：全角转半角 + 去空白 + 转小写
     *
     * 防 `rm -r -f`、大小写混写、全角字符等变体绕过。
     * 注：归一化只是辅助层，真正的保证来自能力归零铁律（系统本无执行命令的工具）。
     */
    public function normalize(string $text): string
    {
        $converted = mb_convert_kana($text, 'as', 'UTF-8');
        $noSpace = preg_replace('/\s+/u', '', $converted) ?? $converted;

        return mb_strtolower($noSpace, 'UTF-8');
    }

    /**
     * 规则匹配：内置规则 + 配置追加关键词，返回命中类别
     */
    private function match(string $normalized): ?string
    {
        foreach (self::BUILTIN_PATTERNS as $category => $patterns) {
            foreach ($patterns as $pattern) {
                if (@preg_match($pattern, $normalized) === 1) {
                    return $category;
                }
            }
        }

        // 配置追加的精确关键词（运维可按租户环境扩展词表）
        $extraKeywords = (array) config('ai.content_guard.keywords', []);
        foreach ($extraKeywords as $keyword) {
            $keyword = $this->normalize((string) $keyword);
            if ($keyword !== '' && str_contains($normalized, $keyword)) {
                return 'custom_keyword';
            }
        }

        return null;
    }

    /**
     * 拦截审计（服务端框架路径；失败静默不影响响应）
     */
    private function recordBlock(string $category, string $excerpt): void
    {
        rescue(function () use ($category, $excerpt) {
            $this->audit?->log('ai_content_guard_block', 'ai_assistant', null, [
                'category' => $category,
                'input_excerpt' => $excerpt,
            ], ['blocked' => true]);
        }, report: false);
    }
}
