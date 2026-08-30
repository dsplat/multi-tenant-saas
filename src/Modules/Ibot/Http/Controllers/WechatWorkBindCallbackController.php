<?php

namespace MultiTenantSaas\Modules\Ibot\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use MultiTenantSaas\Scopes\TenantScope;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Services\Channels\WechatWorkChannel;
use MultiTenantSaas\Modules\Ibot\Services\IbotBindingService;

/**
 * 企微扫码即绑回调（公开端点，无鉴权——企微网页授权 OAuth 回调）
 *
 * 链路（docs/ibot.md 第四节「扫码即绑」）：
 * 扫码（二维码内容 = snsapi_base 授权链接）→ 企微内置浏览器静默授权 →
 * GET /callback?code=&state=ibot_id:绑定码 → 换 userid + 渲染确认页 →
 * 用户点「确认绑定」→ POST /confirm → consume 建立绑定 + 推送「绑定成功」。
 *
 * 安全：userid 仅来自企微回调（getuserinfo）；pending 暂存短时效、
 * 取走即失效；绑定码一次性消费（consume 防跨 bot/租户重放）。
 */
class WechatWorkBindCallbackController extends Controller
{
    public function handle(Request $request): Response
    {
        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');

        if ($code === '' || $state === '') {
            return $this->page('绑定失败', '无效的授权链接，请返回控制台重新生成绑定码。', false);
        }

        [$ibotId, $bindCode] = array_pad(explode(':', $state, 2), 2, null);
        if ($bindCode === null || ! ctype_digit($ibotId)) {
            return $this->page('绑定失败', '无效的授权参数，请返回控制台重新生成绑定码。', false);
        }

        // 公开回调无 TenantContext，显式绕过租户全局作用域（与 IbotWebhookController 同策略）
        $ibot = Ibot::withoutGlobalScope(TenantScope::class)
            ->where('ibot_id', (int) $ibotId)
            ->where('status', Ibot::STATUS_ACTIVE)
            ->first();
        if ($ibot === null) {
            return $this->page('绑定失败', '机器人不存在或已停用，请联系管理员。', false);
        }

        $binding = app(IbotBindingService::class);
        if (! $binding->peekBindCode($bindCode, $ibot)) {
            return $this->page('绑定失败', '绑定码无效或已过期（一次性、默认 10 分钟有效），请返回控制台重新生成。', false);
        }

        // snsapi_base 静默换取成员身份（userid 仅来自企微服务端）
        $identity = app(WechatWorkChannel::class)->apiClient($ibot)->getUserByCode($code);
        $externalId = (string) ($identity['userid'] ?? $identity['UserId'] ?? '');
        if ($externalId === '') {
            return $this->page('绑定失败', '未获取到企业微信成员身份：请确认扫码人是本企业成员，并在应用可见范围内。', false);
        }

        $memberName = $this->memberName($ibot, $externalId);

        // 暂存待确认身份（一次性，POST 确认时取走）
        $binding->putPending((int) $ibot->ibot_id, $bindCode, $externalId);

        return $this->confirmPage($ibot, $bindCode, $memberName, $externalId);
    }

    /**
     * 用户点「确认绑定」后完成绑定（pending 取走即失效，防重放）
     */
    public function confirm(Request $request): Response
    {
        $ibotId = (int) $request->input('ibot_id', 0);
        $code = trim((string) $request->input('code', ''));

        if ($ibotId <= 0 || $code === '') {
            return $this->page('绑定失败', '无效的确认请求，请返回控制台重新扫码。', false);
        }

        // 公开回调无 TenantContext，显式绕过租户全局作用域（与 IbotWebhookController 同策略）
        $ibot = Ibot::withoutGlobalScope(TenantScope::class)
            ->where('ibot_id', $ibotId)
            ->where('status', Ibot::STATUS_ACTIVE)
            ->first();
        if ($ibot === null) {
            return $this->page('绑定失败', '机器人不存在或已停用，请联系管理员。', false);
        }

        $binding = app(IbotBindingService::class);
        $externalId = $binding->takePending($ibotId, $code);

        if ($externalId === null) {
            return $this->page('绑定失败', '确认已过期或已处理，请返回控制台重新扫码。', false);
        }

        $result = $binding->consume($code, $ibot, $externalId);
        if ($result === null) {
            return $this->page('绑定失败', '绑定未完成：绑定码已被使用，或该企业微信账号已绑定其他助理，请返回控制台重新生成。', false);
        }

        // 推送「绑定成功」→ 成员点消息直达应用对话框（应用可见范围内必达）
        $sent = app(WechatWorkChannel::class)->sendMessage(
            $ibot,
            $externalId,
            "绑定成功！我是您的随身 AI 小助理「{$ibot->name}」，现在可以开始对话啦。"
        );

        return $this->page('绑定成功', $sent
            ? "已绑定「{$ibot->name}」。企业微信将收到一条「绑定成功」消息，点开即可开始对话。"
            : '已绑定成功。若未收到推送消息，请确认您在应用可见范围内，或在消息列表打开应用直接对话。', true);
    }

    /**
     * 成员姓名（user/get 读取，失败回退 userid 显示）
     */
    private function memberName(Ibot $ibot, string $externalId): string
    {
        $detail = app(WechatWorkChannel::class)->apiClient($ibot)->getUserById($externalId);

        $name = (string) ($detail['name'] ?? '');
        if ($name === '') {
            $name = (string) ($detail['alias'] ?? '');
        }

        return $name !== '' ? $name : $externalId;
    }

    private function confirmPage(Ibot $ibot, string $code, string $memberName, string $externalId): Response
    {
        $name = e($ibot->name);

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="zh-CN">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
        <title>绑定随身助理</title>
        <style>
          * { margin: 0; padding: 0; box-sizing: border-box; }
          body { font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", sans-serif; background: #f5f6f8; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
          .card { width: 88%; max-width: 380px; background: #fff; border-radius: 12px; padding: 28px 24px; box-shadow: 0 4px 16px rgba(0,0,0,.06); text-align: center; }
          .badge { width: 56px; height: 56px; border-radius: 50%; background: #10b981; color: #fff; font-size: 28px; line-height: 56px; margin: 0 auto 14px; }
          h1 { font-size: 18px; color: #1f2329; margin-bottom: 8px; }
          .desc { font-size: 13px; color: #646a73; line-height: 1.7; margin-bottom: 20px; }
          .member { display: inline-block; background: #f0f9ff; color: #0369a1; border-radius: 6px; padding: 2px 10px; font-size: 13px; margin: 4px 0 2px; }
          button { width: 100%; height: 44px; border: 0; border-radius: 8px; background: #10b981; color: #fff; font-size: 16px; font-weight: 600; cursor: pointer; }
          button:disabled { opacity: .5; }
          .tip { font-size: 12px; color: #8f959e; margin-top: 12px; line-height: 1.6; }
        </style>
        </head>
        <body>
        <div class="card">
          <div class="badge">✦</div>
          <h1>绑定随身 AI 助理</h1>
          <div class="desc">将企业微信账号 <span class="member">{$memberName}</span> 绑定到助理「{$name}」<br>绑定后可直接在企微中与 AI 小助理对话</div>
          <form method="post" action="/api/v1/ibot/bind/wechat-work/confirm" onsubmit="btn.disabled=true;btn.textContent='绑定中…';return true;">
            <input type="hidden" name="ibot_id" value="{$ibot->ibot_id}">
            <input type="hidden" name="code" value="{$code}">
            <button type="submit" id="btn">确认绑定</button>
          </form>
          <div class="tip">绑定由企业微信授权确认，安全可靠；确认后助理将推送一条「绑定成功」消息。</div>
        </div>
        </body>
        </html>
        HTML;

        return response($html)->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * 结果页（成功/失败统一样式）
     */
    private function page(string $title, string $message, bool $success): Response
    {
        $color = $success ? '#10b981' : '#f59e0b';
        $icon = $success ? '✓' : '!';

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="zh-CN">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
        <title>{$title}</title>
        <style>
          * { margin: 0; padding: 0; box-sizing: border-box; }
          body { font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", sans-serif; background: #f5f6f8; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
          .card { width: 88%; max-width: 380px; background: #fff; border-radius: 12px; padding: 32px 24px; box-shadow: 0 4px 16px rgba(0,0,0,.06); text-align: center; }
          .badge { width: 56px; height: 56px; border-radius: 50%; background: {$color}; color: #fff; font-size: 26px; line-height: 56px; margin: 0 auto 14px; }
          h1 { font-size: 18px; color: #1f2329; margin-bottom: 10px; }
          .msg { font-size: 13px; color: #646a73; line-height: 1.8; word-break: break-all; }
          a.close { display: inline-block; margin-top: 18px; font-size: 13px; color: #3370ff; text-decoration: none; }
        </style>
        </head>
        <body>
        <div class="card">
          <div class="badge">{$icon}</div>
          <h1>{$title}</h1>
          <div class="msg">{$message}</div>
          <a class="close" href="javascript:wx.closeWindow ? wx.closeWindow() : window.close()">关闭页面</a>
        </div>
        </body>
        </html>
        HTML;

        return response($html)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
