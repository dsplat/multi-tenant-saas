<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Models\SsoProvider;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Auth\Services\SsoService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\PluginModule;
use MultiTenantSaas\Tests\Schema\SecurityModule;

/**
 * TASK-016 SsoService 单元测试
 *
 * 覆盖：SAML SP（AuthnRequest/Response 解析/签名校验）、OIDC、属性映射、
 *       JIT 用户创建、租户级 IdP 配置管理、SP 元数据生成
 */
class SsoServiceTest extends TestCase
{
    protected array $uses = [PluginModule::class, SecurityModule::class];

    private SsoService $service;

    /** 测试用 IdP 自签证书（仅用于签名校验测试） */
    private string $idpCert = <<<'PEM'
-----BEGIN CERTIFICATE-----
MIIDDzCCAfegAwIBAgIUYx46FMU3ZXrZRD5OFKK8/A+DLtEwDQYJKoZIhvcNAQEL
BQAwFzEVMBMGA1UEAwwMc3NwLXRlc3QtaWRwMB4XDTI2MDYyODIwMDQ1N1oXDTI3
MDYyODIwMDQ1N1owFzEVMBMGA1UEAwwMc3NwLXRlc3QtaWRwMIIBIjANBgkqhkiG
9w0BAQEFAAOCAQ8AMIIBCgKCAQEA1saHf527iABkqK49nF5Ydoy8eIHYEpRMZqqS
mljReC6785aM4kP1BHQ/tDQXDR2NIh6zNvLk8odivvqiypXwZuYTV87K9YEzlt5h
cq21/0S7FugTNylnyJaBC6krqRa5gvHuByCmupyPOuXQEG2VXzRY/nnMjYt0plxY
qieJTZEhwL/lS8FI7MncEGITZUtvdIOK345K/QYIXUyGG9+2svIi+7qV7XUrNH4H
7ZoFkliyKNCdey7YTTJ8RxR6LlL4uPzz8qfDRI6VBkd6euHICXgwRfAuNvOXGoe2
yzAd+UCLcfo8L+rbcOjyhzNsGkjP5zYeqWybsOWrtTxZNY0eQQIDAQABo1MwUTAd
BgNVHQ4EFgQUxhU4L6EKFBYiVzTuphD8dCozazAwHwYDVR0jBBgwFoAUxhU4L6EK
FBYiVzTuphD8dCozazAwDwYDVR0TAQH/BAUwAwEB/zANBgkqhkiG9w0BAQsFAAOC
AQEAGB4degpSU0X9ghSnZgopivf8g6Ka7gMN4yixLtdGbT/1aZ4G2UEud9rSiVe6
uuFonxNjFNnucmRwkjLaL6RV3U3L60RfpP/J7JFnhMS/R+EWHLeWLplMaYHaNWHY
4vX5u8ojG/2oQk4xe/7KTYd7nqHFITQKWG9gDsVwJU/iv88QI0oH7YG1JALHUx4a
OzOim6IPldQr/EhVbAMGAjJRSs6AaEB4vlzrF9BUE1S8LYCAOJSgscMc0n1bpYos
RSGyTOG0wCwPmiWVT/utD2I5ZGlE1lrCrkYexyh8/8/BIx2JZxp6v+uFzZ1xlIXZ
q0xh12U4tGH6A0+2Rny8auMOnw==
-----END CERTIFICATE-----
PEM;

    /** 测试用 IdP 私钥（仅用于在测试中生成签名） */
    private string $idpKey = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvwIBADANBgkqhkiG9w0BAQEFAASCBKkwggSlAgEAAoIBAQDWxod/nbuIAGSo
rj2cXlh2jLx4gdgSlExmqpKaWNF4LrvzloziQ/UEdD+0NBcNHY0iHrM28uTyh2K+
+qLKlfBm5hNXzsr1gTOW3mFyrbX/RLsW6BM3KWfIloELqSupFrmC8e4HIKa6nI86
5dAQbZVfNFj+ecyNi3SmXFiqJ4lNkSHAv+VLwUjsydwQYhNlS290g4rfjkr9Bghd
TIYb37ay8iL7upXtdSs0fgftmgWSWLIo0J17LthNMnxHFHouUvi4/PPyp8NEjpUG
R3p64cgJeDBF8C4285cah7bLMB35QItx+jwv6ttw6PKHM2waSM/nNh6pbJuw5au1
PFk1jR5BAgMBAAECggEAPJJjFn+yqumJefUiFR6ajlykrsvX122RmobSr8sK0H1F
rq4v74RA7s+mQ7lJIv7JlezYmE+qeCfslnXzQXVGBo8ut13v6YtVtF/lVcVeBa8p
oI01FiKMTYr8vAAq+xYYnbCDc+kmfIy47GBx7KArN115F9Pb97Nz45M5wktCMuiO
Ctyu7PcNv/S4JaRvmjQxztxUhWVFCku0t/vnA2U0b8gLCEC/ZH04KWdGrwZkI0UX
M6teVdixC8lqQeNmqq+f4PQzr02J6rs8knq9V/ZqHY0UoFElxorQMOABSjmoSBUe
GrqReGAQk+SbN8/Y3bAJe4pYyQ6c6qH3rI5Sh1fRDQKBgQD3gUjqVuRx1U7XiuSi
SIXjsUtt3jLRleTNJyCTUC2lXwmJjiMLJA1gTI2QeUyt7kKl1wTgdJRwGRxbdPjk
3z4Wy/REWMYnvyYOXw/03C8TEqc0rcv7LrhZJfn806Sr6lPCeUe99up9+Ar5w/yc
40Z1Go3N0ZulucmFMPqb00CRpwKBgQDeJao69v71RZ/GkBdqSf8fYZIfcmF+Qa5w
BzIgNSMMkDid2y5BStgnOapalecNvTGsaF1HsdZakWChOaGwyYfHa/kmjn42ej4c
gaLPlgftp6vnAcFOFiu4HyCmpGoSj+/7YDUBEdoxkQGNYx4zkZRSzLcaixCTqhas
3B/1AzI91wKBgQC2blMCd722shWFBllzzKTzqZEBkJBAr42QMdDvBGBAzoZHH79w
zMgXPRXzcZU1drMlbhGoKAXpgnjGaLe0a2BmiTqozg5w6ZHxcdxfDZSdKFiamagK
ous6uqVC1/U+yl+mrqJUwRUieJwOcYlXUqZyVnpDRMbHJuQFCo8OIG4tbQKBgQDa
4EmFp281ws/WGIq5kwbnqH8MtOoSOCzi/HQK/8/0xPTDs/0zH8cxfsO2VRQ+mTak
JIAj77i/q5WFxP7m7On3Nw9ZSfRZQMCJ3cDIv444PohFJ5mKkpWo6CKHjl9kwqU1
DGmtECXnyHO7Fvne3YVCv6l5YaOHmoKU9p4WDnwmKQKBgQCedRgI/ea0fg099kX0
Cfds9z/hdC4xqtm7ExvrkI75cdZvohxSeb0Vq6ch72fDr8sjIrbi1x7yuQ5yuQGW
iQMCQkzdm8L+lhCwBs/EKLGJX3D6ck7rzLwgzfeNjpXVD3vHSbUS3DXGIpltctGE
l2Jo8kxhAHypIe7g8J5vDHCLQw==
-----END PRIVATE KEY-----
PEM;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
        ]);

        TenantContext::setTenantId('1001');

        $this->service = app(SsoService::class);
    }

    // ---------- 提供方 CRUD ----------

    public function test_create_saml_provider(): void
    {
        $provider = $this->service->createProvider([
            'type' => 'saml',
            'name' => 'default',
            'display_name' => 'Main IdP',
            'entity_id' => 'https://idp.example.com',
            'sso_url' => 'https://idp.example.com/sso',
            'certificate' => $this->idpCert,
        ]);

        $this->assertInstanceOf(SsoProvider::class, $provider);
        $this->assertSame('saml', $provider->type);
        $this->assertSame('default', $provider->name);
        $this->assertTrue($provider->isActive());
        $this->assertSame(1001, (int) $provider->tenant_id);
    }

    public function test_get_and_list_providers(): void
    {
        $this->service->createProvider([
            'type' => 'saml', 'name' => 'idp1', 'sso_url' => 'https://idp1.example.com/sso',
        ]);
        $this->service->createProvider([
            'type' => 'oidc', 'name' => 'idp2', 'client_id' => 'cid', 'client_secret' => 'sec',
            'authorize_url' => 'https://idp2.example.com/authorize',
            'token_url' => 'https://idp2.example.com/token',
            'userinfo_url' => 'https://idp2.example.com/userinfo',
        ]);

        $found = $this->service->getProvider(1001, 'idp1');
        $this->assertNotNull($found);
        $this->assertSame('saml', $found->type);

        $list = $this->service->listProviders(1001);
        $this->assertCount(2, $list);
    }

    public function test_update_and_delete_provider(): void
    {
        $provider = $this->service->createProvider([
            'type' => 'saml', 'name' => 'default', 'sso_url' => 'https://old.example.com/sso',
        ]);

        $updated = $this->service->updateProvider($provider->sso_provider_id, [
            'sso_url' => 'https://new.example.com/sso',
            'status' => 'disabled',
        ]);

        $this->assertSame('https://new.example.com/sso', $updated->sso_url);
        $this->assertFalse($updated->isActive());

        $this->assertTrue($this->service->deleteProvider($provider->sso_provider_id));
        $this->assertNull(SsoProvider::find($provider->sso_provider_id));
    }

    public function test_provider_input_validation_rejects_invalid_type(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->createProvider(['type' => 'invalid', 'name' => 'x']);
    }

    // ---------- SAML SP ----------

    public function test_saml_redirect_url_contains_saml_request(): void
    {
        $provider = $this->service->createProvider([
            'type' => 'saml', 'name' => 'default', 'sso_url' => 'https://idp.example.com/sso',
        ]);

        $url = $this->service->buildSamlRedirectUrl($provider, 'https://sp.example.com/acs', 'state123');

        $this->assertStringStartsWith('https://idp.example.com/sso?', $url);
        $this->assertStringContainsString('SAMLRequest=', $url);
        $this->assertStringContainsString('RelayState=state123', $url);

        // SAMLRequest 应可解码回 AuthnRequest XML
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $xml = gzinflate(base64_decode($params['SAMLRequest']));
        $this->assertStringContainsString('AuthnRequest', $xml);
        $this->assertStringContainsString('https://sp.example.com/acs', $xml);
    }

    public function test_parse_saml_response_extracts_attributes(): void
    {
        $xml = $this->buildUnsignedSamlResponse('user-ext-1', [
            'email' => 'alice@example.com',
            'displayname' => 'Alice Smith',
        ]);

        $parsed = $this->service->parseSamlResponseXml($xml);

        $this->assertSame('user-ext-1', $parsed['nameid']);
        $this->assertSame('https://idp.example.com', $parsed['issuer']);
        $this->assertSame('alice@example.com', $parsed['attributes']['email']);
        $this->assertSame('Alice Smith', $parsed['attributes']['displayname']);
        $this->assertSame('_request1', $parsed['in_response_to']);
    }

    public function test_parse_saml_response_base64_input(): void
    {
        $xml = $this->buildUnsignedSamlResponse('user-ext-2', ['email' => 'bob@example.com']);
        $b64 = base64_encode($xml);

        $parsed = $this->service->parseSamlResponse($b64);

        $this->assertSame('user-ext-2', $parsed['nameid']);
        $this->assertSame('bob@example.com', $parsed['attributes']['email']);
    }

    public function test_parse_invalid_saml_response_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->parseSamlResponse(base64_encode('not-xml'));
    }

    public function test_saml_signature_verifies_for_signed_response(): void
    {
        $xml = $this->buildSignedSamlResponse('user-ext-sig', ['email' => 'sig@example.com']);

        $this->assertTrue($this->service->verifySamlSignature($xml, $this->idpCert));
    }

    public function test_saml_signature_fails_with_wrong_certificate(): void
    {
        $xml = $this->buildSignedSamlResponse('user-ext-sig', ['email' => 'sig@example.com']);

        // 使用一个不同的证书（伪造）
        $wrongCert = "-----BEGIN CERTIFICATE-----\nMIIBdummy\n-----END CERTIFICATE-----\n";
        $this->assertFalse($this->service->verifySamlSignature($xml, $wrongCert));
    }

    public function test_unsigned_saml_response_fails_signature_check(): void
    {
        $xml = $this->buildUnsignedSamlResponse('user-ext-3', ['email' => 'a@example.com']);

        $this->assertFalse($this->service->verifySamlSignature($xml, $this->idpCert));
    }

    // ---------- OIDC ----------

    public function test_oidc_authorize_url_contains_params(): void
    {
        $provider = $this->service->createProvider([
            'type' => 'oidc', 'name' => 'default', 'client_id' => 'cid123',
            'authorize_url' => 'https://idp.example.com/authorize',
            'scope' => 'openid profile email',
        ]);

        $url = $this->service->buildOidcAuthorizeUrl($provider, 'https://sp.example.com/cb', 'st');

        $this->assertStringStartsWith('https://idp.example.com/authorize?', $url);
        $this->assertStringContainsString('client_id=cid123', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('state=st', $url);
        $this->assertStringContainsString('redirect_uri=', $url);
    }

    public function test_oidc_exchange_code_and_userinfo_via_http_fake(): void
    {
        $provider = $this->service->createProvider([
            'type' => 'oidc', 'name' => 'default', 'client_id' => 'cid', 'client_secret' => 'sec',
            'token_url' => 'https://idp.example.com/token',
            'userinfo_url' => 'https://idp.example.com/userinfo',
        ]);

        Http::fake([
            'https://idp.example.com/token' => Http::response([
                'access_token' => 'at-123',
                'id_token' => $this->buildDummyJwt(['sub' => 'oidc-1', 'email' => 'jwt@example.com']),
                'token_type' => 'Bearer',
            ], 200),
            'https://idp.example.com/userinfo' => Http::response([
                'sub' => 'oidc-1',
                'email' => 'oidc@example.com',
                'name' => 'OIDC User',
            ], 200),
        ]);

        $token = $this->service->exchangeOidcCode($provider, 'code-abc', 'https://sp.example.com/cb');
        $this->assertSame('at-123', $token['access_token']);

        $userinfo = $this->service->getOidcUserinfo($provider, 'at-123');
        $this->assertSame('oidc@example.com', $userinfo['email']);
    }

    public function test_oidc_token_exchange_failure_throws(): void
    {
        $provider = $this->service->createProvider([
            'type' => 'oidc', 'name' => 'default', 'client_id' => 'cid', 'client_secret' => 'sec',
            'token_url' => 'https://idp.example.com/token',
            'userinfo_url' => 'https://idp.example.com/userinfo',
        ]);

        Http::fake([
            'https://idp.example.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->service->exchangeOidcCode($provider, 'bad', 'https://sp.example.com/cb');
    }

    // ---------- 属性映射 ----------

    public function test_map_attributes_uses_default_keys(): void
    {
        $provider = $this->service->createProvider([
            'type' => 'oidc', 'name' => 'default', 'client_id' => 'cid',
        ]);

        $mapped = $this->service->mapAttributes($provider, [
            'sub' => 'ext-1',
            'email' => 'x@example.com',
            'name' => 'X',
            'picture' => 'https://avatar.example.com/x.png',
        ]);

        $this->assertSame('ext-1', $mapped['external_id']);
        $this->assertSame('x@example.com', $mapped['email']);
        $this->assertSame('X', $mapped['name']);
        $this->assertSame('https://avatar.example.com/x.png', $mapped['avatar']);
    }

    public function test_map_attributes_uses_custom_mapping(): void
    {
        $provider = $this->service->createProvider([
            'type' => 'saml', 'name' => 'default',
            'attribute_mapping' => [
                'external_id' => 'employeeId',
                'email' => 'mail',
                'name' => 'cn',
            ],
        ]);

        $mapped = $this->service->mapAttributes($provider, [
            'employeeId' => 'emp-9',
            'mail' => 'emp@example.com',
            'cn' => 'Employee Nine',
        ]);

        $this->assertSame('emp-9', $mapped['external_id']);
        $this->assertSame('emp@example.com', $mapped['email']);
        $this->assertSame('Employee Nine', $mapped['name']);
    }

    // ---------- JIT 用户创建 ----------

    public function test_jit_creates_new_user_and_links_account(): void
    {
        $provider = $this->service->createProvider([
            'type' => 'saml', 'name' => 'default', 'sso_url' => 'https://idp.example.com/sso',
        ]);

        [$user, $isNew] = $this->service->findOrCreateUser($provider, [
            'external_id' => 'ext-new-1',
            'email' => 'new@example.com',
            'name' => 'New User',
        ]);

        $this->assertTrue($isNew);
        $this->assertSame('new@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);

        // 已绑定 oauth_account
        $this->assertDatabaseHas('oauth_accounts', [
            'user_id' => $user->user_id,
            'provider' => 'saml:default',
            'provider_id' => 'ext-new-1',
        ]);

        // JIT 加入租户
        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => 1001,
            'user_id' => $user->user_id,
        ]);
    }

    public function test_jit_links_existing_user_by_email(): void
    {
        $provider = $this->service->createProvider([
            'type' => 'saml', 'name' => 'default',
        ]);

        User::unguarded(function () {
            User::create([
                'user_id' => 4001,
                'name' => 'Existing',
                'email' => 'existing@example.com',
                'password' => bcrypt('SomePass1!'),
            ]);
        });

        [$user, $isNew] = $this->service->findOrCreateUser($provider, [
            'external_id' => 'ext-existing',
            'email' => 'existing@example.com',
            'name' => 'Existing',
        ]);

        $this->assertFalse($isNew);
        $this->assertSame(4001, (int) $user->user_id);
        $this->assertDatabaseHas('oauth_accounts', [
            'user_id' => 4001,
            'provider_id' => 'ext-existing',
        ]);
    }

    public function test_jit_reuses_existing_link_on_subsequent_login(): void
    {
        $provider = $this->service->createProvider([
            'type' => 'saml', 'name' => 'default',
        ]);

        // 首次：创建
        [$user1, $isNew1] = $this->service->findOrCreateUser($provider, [
            'external_id' => 'ext-reuse',
            'email' => 'reuse@example.com',
            'name' => 'Reuse',
        ]);
        $this->assertTrue($isNew1);

        // 再次：应复用
        [$user2, $isNew2] = $this->service->findOrCreateUser($provider, [
            'external_id' => 'ext-reuse',
            'email' => 'reuse@example.com',
            'name' => 'Reuse',
        ]);

        $this->assertFalse($isNew2);
        $this->assertSame((int) $user1->user_id, (int) $user2->user_id);
    }

    // ---------- 端到端 handleCallback ----------

    public function test_handle_callback_saml_creates_user_and_issues_token(): void
    {
        $provider = $this->service->createProvider([
            'type' => 'saml', 'name' => 'default',
            'sso_url' => 'https://idp.example.com/sso',
        ]);

        $xml = $this->buildUnsignedSamlResponse('saml-ext-1', [
            'email' => 'saml@example.com',
            'displayname' => 'SAML User',
        ]);

        $result = $this->service->handleCallback($provider, [
            'SAMLResponse' => base64_encode($xml),
        ], 'https://sp.example.com/acs');

        $this->assertTrue($result['is_new_user']);
        $this->assertNotEmpty($result['token']);
        $this->assertGreaterThan(0, $result['token_id']);
        $this->assertSame('saml@example.com', $result['user']->email);
    }

    public function test_handle_callback_saml_with_signature_when_cert_configured(): void
    {
        $provider = $this->service->createProvider([
            'type' => 'saml', 'name' => 'default',
            'sso_url' => 'https://idp.example.com/sso',
            'certificate' => $this->idpCert,
        ]);

        $xml = $this->buildSignedSamlResponse('saml-sig-1', [
            'email' => 'samlsig@example.com',
            'displayname' => 'SAML Signed',
        ]);

        $result = $this->service->handleCallback($provider, [
            'SAMLResponse' => base64_encode($xml),
        ], 'https://sp.example.com/acs');

        $this->assertSame('samlsig@example.com', $result['user']->email);
    }

    public function test_handle_callback_saml_with_bad_signature_when_cert_configured_throws(): void
    {
        $provider = $this->service->createProvider([
            'type' => 'saml', 'name' => 'default',
            'sso_url' => 'https://idp.example.com/sso',
            'certificate' => $this->idpCert,
        ]);

        // 篡改的签名响应（用错误的私钥签名）
        $wrongKey = openssl_pkey_new(['private_key_bits' => 2048]);
        $xml = $this->buildSignedSamlResponse('saml-bad-1', ['email' => 'bad@example.com'], $wrongKey);

        $this->expectException(\RuntimeException::class);
        $this->service->handleCallback($provider, [
            'SAMLResponse' => base64_encode($xml),
        ], 'https://sp.example.com/acs');
    }

    public function test_handle_callback_oidc_full_flow(): void
    {
        $provider = $this->service->createProvider([
            'type' => 'oidc', 'name' => 'default',
            'client_id' => 'cid', 'client_secret' => 'sec',
            'authorize_url' => 'https://idp.example.com/authorize',
            'token_url' => 'https://idp.example.com/token',
            'userinfo_url' => 'https://idp.example.com/userinfo',
        ]);

        Http::fake([
            'https://idp.example.com/token' => Http::response([
                'access_token' => 'at-xyz',
                'id_token' => $this->buildDummyJwt(['sub' => 'oidc-ext-1', 'email' => 'oidc@example.com']),
            ], 200),
            'https://idp.example.com/userinfo' => Http::response([
                'sub' => 'oidc-ext-1',
                'email' => 'oidc@example.com',
                'name' => 'OIDC User',
            ], 200),
        ]);

        $result = $this->service->handleCallback($provider, [
            'code' => 'auth-code-1',
            'state' => 'state-1',
        ], 'https://sp.example.com/cb');

        $this->assertTrue($result['is_new_user']);
        $this->assertSame('oidc@example.com', $result['user']->email);
        $this->assertNotEmpty($result['token']);
    }

    public function test_initiate_returns_redirect_url_for_saml(): void
    {
        $provider = $this->service->createProvider([
            'type' => 'saml', 'name' => 'default', 'sso_url' => 'https://idp.example.com/sso',
        ]);

        $result = $this->service->initiate($provider, 'https://sp.example.com/acs');

        $this->assertNotEmpty($result['redirect_url']);
        $this->assertNotEmpty($result['state']);
    }

    public function test_initiate_returns_redirect_url_for_oidc(): void
    {
        $provider = $this->service->createProvider([
            'type' => 'oidc', 'name' => 'default', 'client_id' => 'cid',
            'authorize_url' => 'https://idp.example.com/authorize',
        ]);

        $result = $this->service->initiate($provider, 'https://sp.example.com/cb');

        $this->assertStringContainsString('https://idp.example.com/authorize', $result['redirect_url']);
    }

    public function test_initiate_rejects_disabled_provider(): void
    {
        $provider = $this->service->createProvider([
            'type' => 'saml', 'name' => 'default', 'sso_url' => 'https://idp.example.com/sso',
            'status' => 'disabled',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->service->initiate($provider, 'https://sp.example.com/acs');
    }

    // ---------- SP 元数据 ----------

    public function test_sp_metadata_contains_entity_and_acs(): void
    {
        $xml = $this->service->buildSpMetadata('https://sp.example.com', 'https://sp.example.com/acs');

        $this->assertStringContainsString('EntityDescriptor', $xml);
        $this->assertStringContainsString('entityID="https://sp.example.com"', $xml);
        $this->assertStringContainsString('AssertionConsumerService', $xml);
        $this->assertStringContainsString('https://sp.example.com/acs', $xml);
    }

    // ---------- 辅助方法 ----------

    /**
     * 构造未签名的 SAML Response XML
     *
     * @param  array<string,string>  $attributes
     */
    private function buildUnsignedSamlResponse(string $nameId, array $attributes): string
    {
        $attrXml = '';
        foreach ($attributes as $name => $value) {
            $attrXml .= '<saml:Attribute Name="' . htmlspecialchars($name, ENT_XML1) . '">'
                . '<saml:AttributeValue>' . htmlspecialchars($value, ENT_XML1) . '</saml:AttributeValue>'
                . '</saml:Attribute>';
        }

        return '<?xml version="1.0"?>'
            . '<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" '
            . 'xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" '
            . 'ID="_response1" InResponseTo="_request1" Version="2.0" IssueInstant="2026-06-29T00:00:00Z">'
            . '<saml:Issuer>https://idp.example.com</saml:Issuer>'
            . '<samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>'
            . '<saml:Assertion>'
            . '<saml:Issuer>https://idp.example.com</saml:Issuer>'
            . '<saml:Subject>'
            . '<saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified">' . htmlspecialchars($nameId, ENT_XML1) . '</saml:NameID>'
            . '</saml:Subject>'
            . '<saml:AttributeStatement>' . $attrXml . '</saml:AttributeStatement>'
            . '</saml:Assertion>'
            . '</samlp:Response>';
    }

    /**
     * 构造已签名的 SAML Response XML（用 IdP 私钥或自定义私钥签名 SignedInfo）
     *
     * @param  array<string,string>  $attributes
     */
    private function buildSignedSamlResponse(string $nameId, array $attributes, ?\OpenSSLAsymmetricKey $overrideKey = null): string
    {
        $unsigned = $this->buildUnsignedSamlResponse($nameId, $attributes);

        // 注入 Signature 块（放在 Issuer 之后）
        $signatureBlock = '<ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#">'
            . '<ds:SignedInfo>'
            . '<ds:CanonicalizationMethod Algorithm="http://www.w3.org/2001/10/xml-exc-c14n#"/>'
            . '<ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"/>'
            . '<ds:Reference URI="">'
            . '<ds:Transforms><ds:Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/></ds:Transforms>'
            . '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
            . '<ds:DigestValue></ds:DigestValue>'
            . '</ds:Reference>'
            . '</ds:SignedInfo>'
            . '<ds:SignatureValue></ds:SignatureValue>'
            . '</ds:Signature>';

        $withSig = preg_replace(
            '/(<saml:Issuer>https:\/\/idp\.example\.com<\/saml:Issuer>)/',
            '$1' . $signatureBlock,
            $unsigned,
            1
        );

        // 使用 DOMDocument 读取并签名 SignedInfo
        $doc = new \DOMDocument;
        $doc->loadXML($withSig);
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        $signedInfo = $xpath->query('//ds:Signature/ds:SignedInfo')->item(0);
        $sigValue = $xpath->query('//ds:Signature/ds:SignatureValue')->item(0);

        $canonicalized = $signedInfo->C14N(true, false);

        $privateKey = $overrideKey !== null ? $overrideKey : openssl_pkey_get_private($this->idpKey);
        openssl_sign($canonicalized, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        $sigValue->textContent = base64_encode($signature);

        // 注意：不可在签名后修改 SignedInfo 内的任何节点（包括 DigestValue），
        // 否则服务端重新 C14N 时会得到不同字节，导致签名校验失败。
        // 本服务仅校验 SignedInfo 的签名，不依赖 DigestValue，故保持空值。

        return $doc->saveXML();
    }

    /**
     * 构造一个不验签的 JWT（仅用于测试 id_token 解析）
     *
     * @param  array<string,mixed>  $claims
     */
    private function buildDummyJwt(array $claims): string
    {
        $header = base64_encode(json_encode(['alg' => 'none', 'typ' => 'JWT']));
        $payload = base64_encode(json_encode($claims));
        $sig = '';

        return $header . '.' . $payload . '.' . $sig;
    }
}
