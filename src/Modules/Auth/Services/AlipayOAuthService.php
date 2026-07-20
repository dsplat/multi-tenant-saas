<?php

namespace MultiTenantSaas\Modules\Auth\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MultiTenantSaas\Modules\Auth\Models\OauthAccount;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Auth\Services\Concerns\ManagesOAuthState;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantUser;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 支付宝 OAuth 认证服务
 *
 * 支付宝 OAuth 与标准 OAuth2 有本质差异：
 * - 使用 RSA2 签名（非 client_secret）
 * - 授权端点独立（非标准 /authorize）
 * - 通过统一网关 gateway.do 调用所有 API
 * - 回调参数是 auth_code（非 code）
 *
 * 因此不能复用 Socialite 的 OAuth2 Provider，需独立实现。
 *
 * 租户级配置（存储在 tenant_settings, group='oauth'）：
 *  - alipay_app_id      支付宝应用 ID（不加密）
 *  - alipay_private_key 应用私钥，PEM 或纯 base64（加密）
 *  - alipay_public_key  支付宝公钥，PEM 或纯 base64（不加密）
 *  - alipay_mode        sandbox / production（不加密）
 *  - alipay_redirect    回调 URL（不加密）
 */
class AlipayOAuthService
{
    use ManagesOAuthState;

    /**
     * 授权端点基础地址
     */
    protected function authorizeBaseUrl(string $mode): string
    {
        return $mode === 'sandbox'
            ? 'https://openauth.alipaydev.com/oauth2/publicAppAuthorize.htm'
            : 'https://openauth.alipay.com/oauth2/publicAppAuthorize.htm';
    }

    /**
     * 统一网关地址
     */
    protected function gatewayUrl(string $mode): string
    {
        return $mode === 'sandbox'
            ? 'https://openapi.alipaydev.com/gateway.do'
            : 'https://openapi.alipay.com/gateway.do';
    }

    /**
     * 获取租户支付宝配置
     *
     * @throws \RuntimeException 当 app_id 或 private_key 未配置
     */
    protected function getConfig(int $tenantId): array
    {
        $appId = TenantSetting::get($tenantId, 'oauth', 'alipay_app_id', '');
        $privateKey = TenantSetting::get($tenantId, 'oauth', 'alipay_private_key', '');

        if (empty($appId) || empty($privateKey)) {
            throw new \RuntimeException(trans('common.oauth_not_configured', ['provider' => 'alipay', 'tenant' => $tenantId]));
        }

        return [
            'app_id' => $appId,
            'private_key' => $privateKey,
            'public_key' => TenantSetting::get($tenantId, 'oauth', 'alipay_public_key', ''),
            'mode' => TenantSetting::get($tenantId, 'oauth', 'alipay_mode', 'production'),
            'redirect' => TenantSetting::get($tenantId, 'oauth', 'alipay_redirect', '/auth/alipay/callback'),
        ];
    }

    /**
     * 生成授权跳转 URL
     */
    public function getAuthorizeUrl(int $tenantId): string
    {
        $config = $this->getConfig($tenantId);

        $state = $this->generateState($tenantId, 'alipay');

        $params = [
            'app_id' => $config['app_id'],
            'scope' => 'auth_user',
            'redirect_uri' => $config['redirect'],
            'state' => $state,
        ];

        return $this->authorizeBaseUrl($config['mode']) . '?' . http_build_query($params);
    }

    /**
     * 处理 OAuth 回调，返回用户信息 + token
     *
     * 返回格式与 SocialiteService::handleCallback 一致：
     *  ['user' => [...], 'token' => ...]
     *
     * @throws HttpException state 校验失败时 abort(403)
     */
    public function handleCallback(int $tenantId): array
    {
        $authCode = (string) request()->input('auth_code', '');
        $state = (string) request()->input('state', '');

        $this->verifyState($state, $tenantId, 'alipay');

        if ($authCode === '') {
            throw new \RuntimeException(trans('common.invalid_request'));
        }

        $tokenData = $this->getAccessToken($tenantId, $authCode);
        $userInfo = $this->getUserInfo($tenantId, $tokenData['access_token'] ?? '');

        $user = $this->findOrCreateUser($userInfo, $tenantId);
        $this->recordOAuthAccount($user, $userInfo, $tokenData, $tenantId);

        return [
            'user' => [
                'user_id' => $user->user_id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'token' => $user->createToken('alipay-login')->plainTextToken,
        ];
    }

    /**
     * 调用 alipay.system.oauth.token 换取 access_token
     *
     * @return array{access_token?: string, user_id?: string, expires_in?: int, refresh_token?: string}
     *
     * @throws \RuntimeException
     */
    public function getAccessToken(int $tenantId, string $authCode): array
    {
        return $this->requestGateway($tenantId, 'alipay.system.oauth.token', [
            'grant_type' => 'authorization_code',
            'code' => $authCode,
        ]);
    }

    /**
     * 调用 alipay.user.userinfo.share 获取用户信息
     *
     * @return array{user_id?: string, nick_name?: string, avatar?: string, gender?: string, province?: string, city?: string, email?: string}
     *
     * @throws \RuntimeException
     */
    public function getUserInfo(int $tenantId, string $accessToken): array
    {
        return $this->requestGateway($tenantId, 'alipay.user.userinfo.share', [
            'auth_token' => $accessToken,
        ]);
    }

    /**
     * 调用统一网关
     *
     * @param  string  $method  支付宝接口方法名，如 alipay.system.oauth.token
     * @param  array  $bizParams  业务参数（与公共参数同级）
     *
     * @throws \RuntimeException 网关请求失败或返回 error_response
     */
    protected function requestGateway(int $tenantId, string $method, array $bizParams): array
    {
        $config = $this->getConfig($tenantId);

        $params = [
            'app_id' => $config['app_id'],
            'method' => $method,
            'charset' => 'UTF-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
        ];

        $params = array_merge($params, $bizParams);
        $params['sign'] = $this->sign($tenantId, $params, $config['private_key']);

        $resp = Http::asForm()->post($this->gatewayUrl($config['mode']), $params);

        if (! $resp->successful()) {
            Log::error('[AlipayOAuthService] gateway http failed', [
                'method' => $method,
                'status' => $resp->status(),
                'body' => $resp->body(),
            ]);
            throw new \RuntimeException('Alipay gateway request failed: HTTP ' . $resp->status());
        }

        $data = $resp->json();

        if (isset($data['error_response'])) {
            $err = $data['error_response'];
            $msg = $err['sub_msg'] ?? $err['msg'] ?? 'unknown error';
            $code = $err['sub_code'] ?? $err['code'] ?? '';
            Log::error('[AlipayOAuthService] gateway error_response', [
                'method' => $method,
                'code' => $code,
                'msg' => $msg,
            ]);
            throw new \RuntimeException('Alipay gateway error: ' . $msg);
        }

        $responseKey = str_replace('.', '_', $method) . '_response';

        if (! isset($data[$responseKey])) {
            throw new \RuntimeException('Alipay gateway response key missing: ' . $responseKey);
        }

        $responseData = $data[$responseKey];
        $responseSign = $data['sign'] ?? '';

        if ($responseSign !== '' && ! $this->verifySign($tenantId, $responseData, $responseSign)) {
            Log::error('[AlipayOAuthService] gateway response sign verification failed', [
                'method' => $method,
            ]);
            throw new \RuntimeException('Alipay gateway response signature verification failed');
        }

        return $responseData;
    }

    /**
     * RSA2 签名
     *
     * 过滤空值与 sign/sign_type，按 key 升序拼接为 k1=v1&k2=v2&... 后签名
     */
    public function sign(int $tenantId, array $params, ?string $privateKey = null): string
    {
        if ($privateKey === null) {
            $config = $this->getConfig($tenantId);
            $privateKey = $config['private_key'];
        }
        $privateKeyPem = $this->normalizePrivateKey($privateKey);

        $params = array_filter($params, fn ($v) => $v !== '' && $v !== null);
        unset($params['sign'], $params['sign_type']);
        ksort($params);
        $data = http_build_query($params);

        if (! openssl_sign($data, $signature, $privateKeyPem, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Alipay RSA2 sign failed');
        }

        return base64_encode($signature);
    }

    /**
     * RSA2 验签（验证网关返回数据的签名）
     *
     * 公钥未配置时返回 false（fail-closed）
     */
    public function verifySign(int $tenantId, array $params, string $sign): bool
    {
        $publicKeyPem = TenantSetting::get($tenantId, 'oauth', 'alipay_public_key', '');
        if (empty($publicKeyPem)) {
            return false;
        }

        $publicKeyPem = $this->normalizePublicKey($publicKeyPem);

        $params = array_filter($params, fn ($v) => $v !== '' && $v !== null);
        unset($params['sign'], $params['sign_type']);
        ksort($params);
        $data = http_build_query($params);

        return openssl_verify($data, base64_decode($sign), $publicKeyPem, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * 将私钥规范化为 PEM 格式（兼容纯 base64 存储）
     */
    protected function normalizePrivateKey(string $key): string
    {
        if (str_contains($key, '-----BEGIN')) {
            return $key;
        }

        $key = trim((string) preg_replace('/\s+/', '', $key));
        $lines = str_split($key, 64);

        return "-----BEGIN RSA PRIVATE KEY-----\n" . implode("\n", $lines) . "\n-----END RSA PRIVATE KEY-----\n";
    }

    /**
     * 将公钥规范化为 PEM 格式（兼容纯 base64 存储）
     */
    protected function normalizePublicKey(string $key): string
    {
        if (str_contains($key, '-----BEGIN')) {
            return $key;
        }

        $key = trim((string) preg_replace('/\s+/', '', $key));
        $lines = str_split($key, 64);

        return "-----BEGIN PUBLIC KEY-----\n" . implode("\n", $lines) . "\n-----END PUBLIC KEY-----\n";
    }

    /**
     * 查找或创建用户
     *
     * 1. 通过 OauthAccount (provider='alipay', provider_id=alipay_user_id) 查找
     * 2. 不存在则创建 User（email 用 user_id@alipay 占位，因支付宝可能不返回 email）
     * 3. 创建 TenantUser 关联
     */
    public function findOrCreateUser(array $alipayUser, int $tenantId): User
    {
        $providerId = $alipayUser['user_id'] ?? '';
        if ($providerId === '') {
            throw new \RuntimeException('Alipay user_id missing');
        }

        $nsProvider = SocialiteService::namespacedProvider('alipay', $tenantId);

        $oauthAccount = OauthAccount::where('provider', $nsProvider)
            ->where('provider_id', $providerId)
            ->first();

        if ($oauthAccount) {
            $existingUser = $oauthAccount->user;

            $isMember = TenantUser::where('tenant_id', $tenantId)
                ->where('user_id', $existingUser->user_id)
                ->where('is_active', true)
                ->exists();

            if (! $isMember) {
                TenantUser::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $existingUser->user_id,
                    'role' => 'end_user',
                    'is_active' => true,
                    'joined_at' => now(),
                ]);
            }

            return $existingUser;
        }

        $email = $alipayUser['email'] ?? '';
        if (empty($email)) {
            $email = $providerId . '@alipay';
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            $user = User::create([
                'name' => $alipayUser['nick_name'] ?? ('alipay_' . $providerId),
                'email' => $email,
                'password' => bcrypt(Str::random(32)),
                'avatar' => $alipayUser['avatar'] ?? null,
                'role' => 'platform_user',
            ]);

            TenantUser::create([
                'tenant_id' => $tenantId,
                'user_id' => $user->user_id,
                'role' => 'end_user',
                'is_active' => true,
                'joined_at' => now(),
            ]);
        }

        return $user;
    }

    /**
     * 记录 OAuth 账号（token 加密存储）
     */
    protected function recordOAuthAccount(User $user, array $userInfo, array $tokenData, int $tenantId): void
    {
        $nsProvider = SocialiteService::namespacedProvider('alipay', $tenantId);

        OauthAccount::updateOrCreate(
            [
                'user_id' => $user->user_id,
                'provider' => $nsProvider,
                'provider_id' => $userInfo['user_id'] ?? '',
            ],
            [
                'tenant_id' => $tenantId,
                'provider_email' => $userInfo['email'] ?? null,
                'provider_name' => $userInfo['nick_name'] ?? null,
                'provider_avatar' => $userInfo['avatar'] ?? null,
                'access_token' => ! empty($tokenData['access_token']) ? encrypt($tokenData['access_token']) : null,
                'refresh_token' => ! empty($tokenData['refresh_token']) ? encrypt($tokenData['refresh_token']) : null,
                'token_expires_at' => ! empty($tokenData['expires_in']) ? now()->addSeconds((int) $tokenData['expires_in']) : null,
            ]
        );
    }

    /**
     * 检查租户是否已配置支付宝 OAuth
     */
    public function isConfigured(int $tenantId): bool
    {
        $appId = TenantSetting::get($tenantId, 'oauth', 'alipay_app_id', '');
        $privateKey = TenantSetting::get($tenantId, 'oauth', 'alipay_private_key', '');

        return ! empty($appId) && ! empty($privateKey);
    }
}
