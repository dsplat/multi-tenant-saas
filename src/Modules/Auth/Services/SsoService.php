<?php

namespace MultiTenantSaas\Modules\Auth\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Models\OauthAccount;
use MultiTenantSaas\Modules\Auth\Models\SsoProvider;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantUser;

/**
 * SSO / SAML 集成服务
 *
 * 功能：
 *  1. SAML 2.0 Service Provider（AuthnRequest 生成、Response 解析、签名校验）
 *  2. OIDC 集成（授权码流程、userinfo 拉取）
 *  3. IdP 元数据 / 配置管理（租户级）
 *  4. Just-In-Time 用户创建（首次登录自动建账）
 *  5. 属性映射（IdP 属性 -> 本地字段）
 *
 * 说明：
 *  - SAML 签名校验基于 XMLDSIG + 独家规范化（DOMDocument::C14N），
 *    支持 RSA-SHA256，覆盖大多数 IdP（如 Azure AD、Okta、SimpleSAMLphp）。
 *  - SSO 身份与本地用户的关联复用 oauth_accounts 表，
 *    provider 字段以 "{type}:{name}" 形式命名空间化。
 *  - 路由层无需认证中间件（回调发生在登录前）。
 */
class SsoService
{
    /** SP 默认 EntityID 前缀 */
    private const SP_ENTITY_PREFIX = 'saml:sp';

    /**
     * 列出租户的所有 SSO 提供方
     *
     * @return Collection<int, SsoProvider>
     */
    public function listProviders(int $tenantId): Collection
    {
        return SsoProvider::where('tenant_id', $tenantId)->orderBy('created_at')->get();
    }

    /**
     * 按名称获取租户的 SSO 提供方
     */
    public function getProvider(int $tenantId, string $name): ?SsoProvider
    {
        return SsoProvider::where('tenant_id', $tenantId)
            ->where('name', $name)
            ->first();
    }

    /**
     * 创建 SSO 提供方
     *
     * @param  array<string, mixed>  $data
     */
    public function createProvider(array $data): SsoProvider
    {
        $this->validateProviderInput($data);

        $data['tenant_id'] = $data['tenant_id'] ?? TenantContext::getId();
        // 显式填充默认值（避免依赖 DB 默认值，确保创建后内存模型可读）
        $data['status'] = $data['status'] ?? SsoProvider::STATUS_ACTIVE;
        $data['scope'] = $data['scope'] ?? 'openid profile email';

        return SsoProvider::create($data);
    }

    /**
     * 更新 SSO 提供方
     *
     * @param  array<string, mixed>  $data
     */
    public function updateProvider(int $ssoProviderId, array $data): ?SsoProvider
    {
        $provider = SsoProvider::find($ssoProviderId);
        if (! $provider) {
            return null;
        }

        // client_secret / certificate 允许留空（不覆盖原值）
        foreach (['client_secret', 'certificate'] as $keepField) {
            if (array_key_exists($keepField, $data) && ($data[$keepField] === '' || $data[$keepField] === null)) {
                unset($data[$keepField]);
            }
        }

        $provider->fill($data)->save();

        return $provider;
    }

    /**
     * 删除 SSO 提供方
     */
    public function deleteProvider(int $ssoProviderId): bool
    {
        $provider = SsoProvider::find($ssoProviderId);
        if (! $provider) {
            return false;
        }

        return (bool) $provider->delete();
    }

    /**
     * 发起 SSO 登录：生成跳转到 IdP 的 URL
     *
     * @return array{redirect_url: string, state: string}
     */
    public function initiate(SsoProvider $provider, string $acsUrl): array
    {
        if (! $provider->isActive()) {
            throw new \RuntimeException(trans('auth.sso_provider_disabled'));
        }

        $state = Str::random(32);

        if ($provider->isSaml()) {
            $url = $this->buildSamlRedirectUrl($provider, $acsUrl, $state);
        } elseif ($provider->isOidc()) {
            $url = $this->buildOidcAuthorizeUrl($provider, $acsUrl, $state);
        } else {
            throw new \RuntimeException(trans('auth.sso_provider_type_invalid'));
        }

        return ['redirect_url' => $url, 'state' => $state];
    }

    /**
     * 处理 SSO 回调，完成身份认证并签发令牌
     *
     * @param  array<string, mixed>  $input  SAML: SAMLResponse/RelayState；OIDC: code/state
     * @return array{user: User, token: string, token_id: int, is_new_user: bool}
     */
    public function handleCallback(SsoProvider $provider, array $input, string $acsUrl): array
    {
        if (! $provider->isActive()) {
            throw new \RuntimeException(trans('auth.sso_provider_disabled'));
        }

        if ($provider->isSaml()) {
            $rawAttributes = $this->handleSamlCallback($provider, $input);
        } elseif ($provider->isOidc()) {
            $rawAttributes = $this->handleOidcCallback($provider, $input, $acsUrl);
        } else {
            throw new \RuntimeException(trans('auth.sso_provider_type_invalid'));
        }

        $attributes = $this->mapAttributes($provider, $rawAttributes);

        if (empty($attributes['external_id'])) {
            throw new \RuntimeException(trans('auth.sso_external_id_missing'));
        }

        [$user, $isNew] = $this->findOrCreateUser($provider, $attributes);

        $newToken = $user->createToken('sso-token', ['*']);

        return [
            'user' => $user,
            'token' => $newToken->plainTextToken,
            'token_id' => $newToken->accessToken->id,
            'is_new_user' => $isNew,
        ];
    }

    /**
     * 生成 SAML SP 元数据 XML（供 IdP 注册）
     */
    public function buildSpMetadata(string $spEntityId, string $acsUrl): string
    {
        $validUntil = now()->addDays(365)->format('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0"?>' . "\n"
            . '<EntityDescriptor xmlns="urn:oasis:names:tc:SAML:2.0:metadata" '
            . 'entityID="' . htmlspecialchars($spEntityId, ENT_XML1) . '" '
            . 'validUntil="' . $validUntil . '">'
            . '<SPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">'
            . '<NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified</NameIDFormat>'
            . '<AssertionConsumerService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" '
            . 'Location="' . htmlspecialchars($acsUrl, ENT_XML1) . '" index="0"/>'
            . '</SPSSODescriptor>'
            . '</EntityDescriptor>';
    }

    // ----------------------------------------
    // SAML
    // ----------------------------------------

    /**
     * 构造 SAML HTTP-Redirect 跳转 URL
     */
    public function buildSamlRedirectUrl(SsoProvider $provider, string $acsUrl, string $state): string
    {
        $requestId = '_' . Str::uuid()->toString();
        $issueInstant = now()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z');

        $spEntityId = (string) config('socialite.saml.sp_entity_id', self::SP_ENTITY_PREFIX);

        $authnRequest = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<samlp:AuthnRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" '
            . 'xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" '
            . 'ID="' . $requestId . '" '
            . 'Version="2.0" '
            . 'IssueInstant="' . $issueInstant . '" '
            . 'Destination="' . htmlspecialchars($provider->sso_url ?? '', ENT_XML1) . '" '
            . 'AssertionConsumerServiceURL="' . htmlspecialchars($acsUrl, ENT_XML1) . '">'
            . '<saml:Issuer>' . htmlspecialchars($spEntityId, ENT_XML1) . '</saml:Issuer>'
            . '<samlp:NameIDPolicy AllowCreate="true" Format="urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified"/>'
            . '</samlp:AuthnRequest>';

        // HTTP-Redirect 绑定：DEFLATE(base64(XML))
        $deflated = gzdeflate($authnRequest, 9);
        $samlRequest = base64_encode($deflated);

        $url = $provider->sso_url ?? '';
        $separator = str_contains($url, '?') ? '&' : '?';
        $query = http_build_query([
            'SAMLRequest' => $samlRequest,
            'RelayState' => $state,
        ]);

        return $url . $separator . $query;
    }

    /**
     * 解析 SAML Response（HTTP-POST 绑定，base64 编码输入）
     *
     * @return array{nameid: string|null, attributes: array<string,string|array<string>>, issuer: string|null, in_response_to: string|null}
     */
    public function parseSamlResponse(string $samlResponseBase64): array
    {
        $xml = base64_decode($samlResponseBase64, true);
        if ($xml === false) {
            throw new \RuntimeException(trans('auth.saml_response_invalid'));
        }

        return $this->parseSamlResponseXml($xml);
    }

    /**
     * 解析 SAML Response XML（明文输入）
     *
     * @return array{nameid: string|null, attributes: array<string,string|array<string>>, issuer: string|null, in_response_to: string|null}
     */
    public function parseSamlResponseXml(string $xml): array
    {
        $doc = new \DOMDocument;
        $previousLibxml = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxml);

        if (! $loaded) {
            throw new \RuntimeException(trans('auth.saml_response_invalid'));
        }

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('samlp', 'urn:oasis:names:tc:SAML:2.0:protocol');
        $xpath->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        // Issuer
        $issuerNode = $xpath->query('//saml:Issuer')->item(0);
        $issuer = $issuerNode?->textContent ?: null;

        // InResponseTo
        $responseNode = $xpath->query('//samlp:Response')->item(0);
        $inResponseTo = $responseNode?->getAttribute('InResponseTo') ?: null;

        // NameID
        $nameidNode = $xpath->query('//saml:Subject/saml:NameID')->item(0);
        if (! $nameidNode) {
            // 部分 IdP 将 NameID 放在 Subject 之外，尝试后备查询
            $nameidNode = $xpath->query('//saml:NameID')->item(0);
        }
        $nameid = $nameidNode?->textContent ?: null;

        // 属性
        $attributes = [];
        $attrNodes = $xpath->query('//saml:AttributeStatement/saml:Attribute');
        foreach ($attrNodes as $attrNode) {
            $name = $attrNode->getAttribute('Name');
            if ($name === '') {
                continue;
            }
            $values = [];
            foreach ($xpath->query('saml:AttributeValue', $attrNode) as $valueNode) {
                $values[] = trim($valueNode->textContent);
            }
            $attributes[$name] = count($values) === 1 ? $values[0] : $values;
        }

        return [
            'nameid' => $nameid !== null ? trim($nameid) : null,
            'attributes' => $attributes,
            'issuer' => $issuer !== null ? trim($issuer) : null,
            'in_response_to' => $inResponseTo !== '' ? $inResponseTo : null,
        ];
    }

    /**
     * 校验 SAML Response 的 XML 数字签名
     *
     * 基于 XMLDSIG + 独家规范化（C14N），支持 RSA-SHA256。
     * 若 Response 未携带签名或证书缺失，返回 false。
     */
    public function verifySamlSignature(string $xml, string $certificate): bool
    {
        if ($certificate === '' || ! str_contains($xml, 'Signature')) {
            return false;
        }

        // 提取 PEM 证书（处理 BEGIN/END 包裹与裸 base64 两种情形）
        $pem = $this->normalizeCertificatePem($certificate);

        $doc = new \DOMDocument;
        $previousLibxml = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxml);

        if (! $loaded) {
            return false;
        }

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        $signatureNode = $xpath->query('//ds:Signature')->item(0);
        if (! $signatureNode instanceof \DOMElement) {
            return false;
        }

        $signedInfoNode = $xpath->query('ds:SignedInfo', $signatureNode)->item(0);
        $signatureValueNode = $xpath->query('ds:SignatureValue', $signatureNode)->item(0);

        if (! $signedInfoNode instanceof \DOMElement || ! $signatureValueNode instanceof \DOMElement) {
            return false;
        }

        // 签名算法
        $sigMethodNode = $xpath->query('ds:SignatureMethod', $signedInfoNode)->item(0);
        $algorithm = $sigMethodNode?->getAttribute('Algorithm') ?: '';
        $opensslAlg = match ($algorithm) {
            'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256' => OPENSSL_ALGO_SHA256,
            'http://www.w3.org/2001/04/xmldsig-more#rsa-sha384' => defined('OPENSSL_ALGO_SHA384') ? OPENSSL_ALGO_SHA384 : OPENSSL_ALGO_SHA256,
            'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512' => defined('OPENSSL_ALGO_SHA512') ? OPENSSL_ALGO_SHA512 : OPENSSL_ALGO_SHA256,
            'http://www.w3.org/2000/09/xmldsig#rsa-sha1' => OPENSSL_ALGO_SHA1,
            default => OPENSSL_ALGO_SHA256,
        };

        // 独家规范化 SignedInfo（含子节点注释）
        $canonicalized = $signedInfoNode->C14N(true, false);

        $signatureValue = base64_decode(trim($signatureValueNode->textContent), true);
        if ($signatureValue === false) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($pem);
        if (! $publicKey) {
            return false;
        }

        $result = openssl_verify($canonicalized, $signatureValue, $publicKey, $opensslAlg);
        openssl_free_key($publicKey);

        return $result === 1;
    }

    // ----------------------------------------
    // OIDC
    // ----------------------------------------

    /**
     * 构造 OIDC 授权码跳转 URL
     */
    public function buildOidcAuthorizeUrl(SsoProvider $provider, string $acsUrl, string $state): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $provider->client_id ?? '',
            'redirect_uri' => $acsUrl,
            'scope' => $provider->scope ?? 'openid profile email',
            'state' => $state,
        ];

        $url = $provider->authorize_url ?? '';
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query($params);
    }

    /**
     * 使用授权码换取 OIDC 令牌并拉取 userinfo
     *
     * @return array<string,mixed>
     */
    public function exchangeOidcCode(SsoProvider $provider, string $code, string $acsUrl): array
    {
        $response = Http::asForm()->post($provider->token_url ?? '', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $acsUrl,
            'client_id' => $provider->client_id ?? '',
            'client_secret' => $provider->client_secret ?? '',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(trans('auth.oidc_token_exchange_failed'));
        }

        $tokenData = $response->json();
        if (! is_array($tokenData) || empty($tokenData['access_token'])) {
            throw new \RuntimeException(trans('auth.oidc_token_exchange_failed'));
        }

        return $tokenData;
    }

    /**
     * 拉取 OIDC userinfo
     *
     * @return array<string,mixed>
     */
    public function getOidcUserinfo(SsoProvider $provider, string $accessToken): array
    {
        $response = Http::withToken($accessToken)->get($provider->userinfo_url ?? '');

        if (! $response->successful()) {
            throw new \RuntimeException(trans('auth.oidc_userinfo_failed'));
        }

        $userinfo = $response->json();
        if (! is_array($userinfo)) {
            throw new \RuntimeException(trans('auth.oidc_userinfo_failed'));
        }

        return $userinfo;
    }

    // ----------------------------------------
    // JIT 用户创建与属性映射
    // ----------------------------------------

    /**
     * 属性映射：将 IdP 原始属性映射为统一结构
     *
     * @param  array<string,mixed>  $raw
     * @return array{external_id: string|null, email: string|null, name: string|null, avatar: string|null, extra: array<string,mixed>}
     */
    public function mapAttributes(SsoProvider $provider, array $raw): array
    {
        $mapping = is_array($provider->attribute_mapping) ? $provider->attribute_mapping : [];

        $externalIdKey = $mapping['external_id'] ?? ($provider->isOidc() ? 'sub' : 'nameid');
        $emailKey = $mapping['email'] ?? 'email';
        $nameKey = $mapping['name'] ?? 'name';
        $avatarKey = $mapping['avatar'] ?? 'picture';

        return [
            'external_id' => $this->pickAttribute($raw, $externalIdKey),
            'email' => $this->pickAttribute($raw, $emailKey),
            'name' => $this->pickAttribute($raw, $nameKey),
            'avatar' => $this->pickAttribute($raw, $avatarKey),
            'extra' => $raw,
        ];
    }

    /**
     * Just-In-Time 用户创建 / 关联
     *
     * 策略：
     *  1. 按 (provider, external_id) 查找已绑定的 oauth_account，命中即返回对应用户
     *  2. 按 email 查找用户，命中则绑定 oauth_account
     *  3. 否则创建新用户 + TenantUser + oauth_account
     *
     * @param  array{external_id: string|null, email: string|null, name: string|null, avatar: string|null}  $attributes
     * @return array{0: User, 1: bool} [user, is_new_user]
     */
    public function findOrCreateUser(SsoProvider $provider, array $attributes): array
    {
        return DB::transaction(function () use ($provider, $attributes) {
            // 命名空间化 provider，确保跨租户隔离
            $providerKey = $provider->type . ':' . $provider->name . ':tenant:' . $provider->tenant_id;
            $externalId = (string) ($attributes['external_id'] ?? '');
            $email = $attributes['email'] ? strtolower(trim($attributes['email'])) : null;

            // 1. 已绑定
            $linked = OauthAccount::where('provider', $providerKey)
                ->where('provider_id', $externalId)
                ->first();
            if ($linked) {
                $user = User::find($linked->user_id);
                if ($user) {
                    return [$user, false];
                }
            }

            // 2. 按 email 匹配
            $user = null;
            if ($email) {
                $user = User::where('email', $email)->first();
            }

            $isNew = false;

            // 3. 创建新用户
            if (! $user) {
                $isNew = true;
                $user = new User;
                $user->name = $attributes['name'] ?: ('SSO User ' . substr($attributes['external_id'] ?? '', 0, 8));
                $user->email = $email ?: ($attributes['external_id'] . '@sso.local');
                $user->password = bin2hex(random_bytes(16));
                $user->avatar = $attributes['avatar'] ?? null;
                $user->email_verified_at = $email ? now() : null;
                $user->password_changed_at = now();
                $user->login_attempts = 0;
                $user->is_active = true;
                $user->save();
            }

            // 关联 oauth_account（若尚未绑定）
            if (! $linked) {
                OauthAccount::create([
                    'tenant_id' => $provider->tenant_id,
                    'user_id' => $user->user_id,
                    'provider' => $providerKey,
                    'provider_id' => $externalId,
                    'provider_email' => $email,
                    'provider_name' => $attributes['name'] ?? null,
                    'provider_avatar' => $attributes['avatar'] ?? null,
                ]);
            }

            // 创建租户成员关系（JIT 加入）
            $tenantId = (int) $provider->tenant_id;
            if (! TenantUser::where('tenant_id', $tenantId)->where('user_id', $user->user_id)->exists()) {
                // 获取 end_user 角色 ID
                $endUserRoleId = \DB::table('roles')
                    ->where('name', 'end_user')
                    ->whereNull('tenant_id')
                    ->value('role_id');

                TenantUser::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $user->user_id,
                    'role_id' => $endUserRoleId,
                    'is_active' => true,
                    'joined_at' => now(),
                ]);
            }

            return [$user, $isNew];
        });
    }

    // ----------------------------------------
    // 私有辅助方法
    // ----------------------------------------

    /**
     * 处理 SAML 回调
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function handleSamlCallback(SsoProvider $provider, array $input): array
    {
        $samlResponse = (string) ($input['SAMLResponse'] ?? '');
        if ($samlResponse === '') {
            throw new \RuntimeException(trans('auth.saml_response_missing'));
        }

        $xml = base64_decode($samlResponse, true);
        if ($xml === false) {
            throw new \RuntimeException(trans('auth.saml_response_invalid'));
        }

        // 签名校验（如果配置了证书则强制校验）
        $certificate = (string) ($provider->certificate ?? '');
        if ($certificate !== '' && ! $this->verifySamlSignature($xml, $certificate)) {
            throw new \RuntimeException(trans('auth.saml_signature_invalid'));
        }

        $parsed = $this->parseSamlResponseXml($xml);

        // NameID 作为 external_id 放入 raw 属性
        $attributes = $parsed['attributes'];
        $attributes['nameid'] = $parsed['nameid'] ?? '';
        $attributes['issuer'] = $parsed['issuer'] ?? '';

        return $attributes;
    }

    /**
     * 处理 OIDC 回调
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function handleOidcCallback(SsoProvider $provider, array $input, string $acsUrl): array
    {
        $code = (string) ($input['code'] ?? '');
        if ($code === '') {
            throw new \RuntimeException(trans('auth.oidc_code_missing'));
        }

        $tokenData = $this->exchangeOidcCode($provider, $code, $acsUrl);
        $userinfo = $this->getOidcUserinfo($provider, (string) $tokenData['access_token']);

        // 解析 id_token 中的声明（不强制校验签名，由 IdP 元数据信任保证）
        if (! empty($tokenData['id_token']) && is_string($tokenData['id_token'])) {
            $idClaims = $this->decodeJwtPayload($tokenData['id_token']);
            $userinfo = array_merge($idClaims, $userinfo);
        }

        return $userinfo;
    }

    /**
     * 解码 JWT payload（不验签，仅用于读取声明）
     *
     * @return array<string,mixed>
     */
    private function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            throw new \RuntimeException(trans('auth.oidc_jwt_invalid'));
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if ($payload === false) {
            throw new \RuntimeException(trans('auth.oidc_jwt_invalid'));
        }

        $data = json_decode($payload, true);

        if (! is_array($data)) {
            throw new \RuntimeException(trans('auth.oidc_jwt_invalid'));
        }

        return $data;
    }

    /**
     * 从原始属性中取值（支持点号路径）
     */
    private function pickAttribute(array $raw, string $key): ?string
    {
        if ($key === '') {
            return null;
        }

        $value = $raw;
        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return $value !== null ? (string) $value : null;
    }

    /**
     * 将证书规范化为 PEM 格式
     */
    private function normalizeCertificatePem(string $certificate): string
    {
        $certificate = trim($certificate);
        if (str_contains($certificate, '-----BEGIN CERTIFICATE-----')) {
            return $certificate;
        }

        // 去除空白与换行
        $body = preg_replace('/\s+/', '', $certificate) ?? '';

        $lines = str_split($body, 64);

        return "-----BEGIN CERTIFICATE-----\n"
            . implode("\n", $lines)
            . "\n-----END CERTIFICATE-----\n";
    }

    /**
     * 校验提供方输入
     *
     * @param  array<string,mixed>  $data
     */
    private function validateProviderInput(array $data): void
    {
        $validator = Validator::make($data, [
            'type' => ['required', 'in:saml,oidc'],
            'name' => ['required', 'string', 'max:100'],
            'display_name' => ['nullable', 'string', 'max:200'],
            'sso_url' => ['nullable', 'string', 'max:500'],
            'entity_id' => ['nullable', 'string', 'max:500'],
            'metadata_url' => ['nullable', 'string', 'max:500'],
            'client_id' => ['nullable', 'string', 'max:200'],
            'client_secret' => ['nullable', 'string'],
            'authorize_url' => ['nullable', 'string', 'max:500'],
            'token_url' => ['nullable', 'string', 'max:500'],
            'userinfo_url' => ['nullable', 'string', 'max:500'],
            'scope' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'in:active,disabled'],
        ]);

        if ($validator->fails()) {
            throw new \RuntimeException(implode(';', $validator->errors()->all()));
        }
    }
}
