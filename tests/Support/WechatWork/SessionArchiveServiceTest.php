<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests\Support\WechatWork;

use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Modules\Conversation\Models\ArchivedMessage;
use MultiTenantSaas\Support\WechatWork\ArchiveDecryptor;
use MultiTenantSaas\Support\WechatWork\SessionArchiveService;
use MultiTenantSaas\Tests\Schema\ChannelModule;
use MultiTenantSaas\Tests\TestCase;

class SessionArchiveServiceTest extends TestCase
{
    protected array $uses = [ChannelModule::class];

    private string $encodingAesKey;

    private string $aesKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->encodingAesKey = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->aesKey = substr(base64_decode($this->encodingAesKey . '='), 0, 32);
    }

    private function encryptPayload(array $data): string
    {
        $plaintext = json_encode($data, JSON_UNESCAPED_UNICODE);
        $iv = substr($this->aesKey, 0, 16);

        $padded = $this->addPkcs7Padding($plaintext);
        $encrypted = openssl_encrypt($padded, 'AES-256-CBC', $this->aesKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);

        return base64_encode($iv . $encrypted);
    }

    private function addPkcs7Padding(string $data): string
    {
        $padLen = 32 - (strlen($data) % 32);

        return $data . str_repeat(chr($padLen), $padLen);
    }

    public function test_archive_decryptor_basic(): void
    {
        $decryptor = new ArchiveDecryptor($this->encodingAesKey);

        $original = 'Hello, World!';
        $encrypted = $this->encryptPayload(['text' => $original]);

        $decrypted = $decryptor->decrypt($encrypted);
        $data = json_decode($decrypted, true);

        $this->assertSame($original, $data['text']);
    }

    public function test_archive_decryptor_empty_key_throws(): void
    {
        $this->expectException(\RuntimeException::class);

        new ArchiveDecryptor('');
    }

    public function test_archive_decryptor_invalid_data(): void
    {
        $decryptor = new ArchiveDecryptor($this->encodingAesKey);

        $result = $decryptor->decrypt('invalid-base64-data!!!');

        $this->assertSame('', $result);
    }

    public function test_archive_decryptor_batch(): void
    {
        $decryptor = new ArchiveDecryptor($this->encodingAesKey);

        $messages = [
            $this->encryptPayload(['id' => 1]),
            $this->encryptPayload(['id' => 2]),
            $this->encryptPayload(['id' => 3]),
        ];

        $results = $decryptor->batchDecrypt($messages);

        $this->assertCount(3, $results);
        $this->assertSame(1, json_decode($results[0], true)['id']);
        $this->assertSame(2, json_decode($results[1], true)['id']);
        $this->assertSame(3, json_decode($results[2], true)['id']);
    }

    public function test_session_archive_service_fetch(): void
    {
        $config = [
            'corp_id' => 'test_corp',
            'corp_secret' => 'test_secret',
            'encoding_aes_key' => $this->encodingAesKey,
        ];

        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response([
                'errcode' => 0,
                'access_token' => 'test_token',
                'expires_in' => 7200,
            ]),
            'qyapi.weixin.qq.com/cgi-bin/msgaudit/get_chat_data*' => Http::response([
                'errcode' => 0,
                'chatdata' => [
                    ['encrypt_chat_msg' => $this->encryptPayload(['msg_id' => 'msg_001', 'room_id' => 'room_1', 'msgtype' => 'text'])],
                ],
                'seq' => 100,
            ]),
        ]);

        $service = new SessionArchiveService($config);
        $result = $service->fetchFromApi(0, 100);

        $this->assertArrayHasKey('chatdata', $result);
        $this->assertCount(1, $result['chatdata']);
        $this->assertSame(100, $result['seq']);
    }

    public function test_session_archive_service_decrypt_and_store(): void
    {
        $config = [
            'corp_id' => 'test_corp',
            'corp_secret' => 'test_secret',
            'encoding_aes_key' => $this->encodingAesKey,
        ];

        $service = new SessionArchiveService($config);

        $chatData = [
            ['encrypt_chat_msg' => $this->encryptPayload([
                'msg_id' => 'msg_002',
                'room_id' => 'room_1',
                'msgtype' => 'text',
                'from_userid' => 'user1',
                'seq' => 1,
                'msgtime' => '1625000000',
            ])],
        ];

        $stored = $service->decryptAndStore($chatData, 1001);

        $this->assertSame(1, $stored);
        $this->assertDatabaseHas('archived_messages', ['msg_id' => 'msg_002']);
    }

    public function test_session_archive_service_duplicate_prevention(): void
    {
        $config = [
            'corp_id' => 'test_corp',
            'corp_secret' => 'test_secret',
            'encoding_aes_key' => $this->encodingAesKey,
        ];

        $service = new SessionArchiveService($config);

        $chatData = [
            ['encrypt_chat_msg' => $this->encryptPayload([
                'msg_id' => 'msg_003',
                'room_id' => 'room_1',
                'msgtype' => 'text',
                'from_userid' => 'user1',
                'seq' => 1,
            ])],
        ];

        $stored1 = $service->decryptAndStore($chatData);
        $stored2 = $service->decryptAndStore($chatData);

        $this->assertSame(1, $stored1);
        $this->assertSame(0, $stored2);
    }

    public function test_session_archive_service_query_messages(): void
    {
        $config = [
            'corp_id' => 'test_corp',
            'corp_secret' => 'test_secret',
            'encoding_aes_key' => $this->encodingAesKey,
        ];

        $service = new SessionArchiveService($config);

        for ($i = 1; $i <= 3; $i++) {
            $service->storeMessage([
                'msg_id' => "msg_q_{$i}",
                'room_id' => 'room_query',
                'msgtype' => 'text',
                'from_userid' => 'user1',
                'seq' => $i,
            ], 1001);
        }

        $messages = $service->queryMessages('room_query', 2, 0);

        $this->assertCount(2, $messages);
        $this->assertSame('msg_q_1', $messages[0]->msg_id);
        $this->assertSame('msg_q_2', $messages[1]->msg_id);
    }

    public function test_session_archive_service_store_empty_msg_id(): void
    {
        $config = [
            'corp_id' => 'test_corp',
            'corp_secret' => 'test_secret',
            'encoding_aes_key' => $this->encodingAesKey,
        ];

        $service = new SessionArchiveService($config);

        $result = $service->storeMessage(['msg_id' => '', 'room_id' => 'room_1']);

        $this->assertFalse($result);
    }

    public function test_session_archive_service_fetch_api_error(): void
    {
        $config = [
            'corp_id' => 'test_corp',
            'corp_secret' => 'test_secret',
            'encoding_aes_key' => $this->encodingAesKey,
        ];

        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response([
                'errcode' => 0,
                'access_token' => 'test_token',
                'expires_in' => 7200,
            ]),
            'qyapi.weixin.qq.com/cgi-bin/msgaudit/get_chat_data*' => Http::response([
                'errcode' => 40001,
                'errmsg' => 'invalid credential',
            ]),
        ]);

        $service = new SessionArchiveService($config);
        $result = $service->fetchFromApi(0, 100);

        $this->assertEmpty($result['chatdata']);
    }

    public function test_session_archive_service_fetch_token_failure(): void
    {
        $config = [
            'corp_id' => 'test_corp',
            'corp_secret' => 'test_secret',
            'encoding_aes_key' => $this->encodingAesKey,
        ];

        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response([
                'errcode' => 40001,
                'errmsg' => 'invalid credential',
            ]),
        ]);

        $service = new SessionArchiveService($config);
        $result = $service->fetchFromApi(0, 100);

        $this->assertEmpty($result['chatdata']);
    }

    public function test_archive_message_model(): void
    {
        $msg = ArchivedMessage::create([
            'tenant_id' => 1001,
            'msg_id' => 'msg_model_001',
            'room_id' => 'room_1',
            'msg_type' => 'text',
            'from_user' => 'user1',
            'content' => ['text' => 'hello'],
            'raw_data' => ['raw' => true],
            'seq' => 42,
            'create_time' => '2026-07-02 10:00:00',
        ]);

        $this->assertNotNull($msg->archived_message_id);
        $this->assertSame('msg_model_001', $msg->msg_id);
        $this->assertSame(['text' => 'hello'], $msg->content);
        $this->assertSame(42, $msg->seq);
    }

    public function test_full_lifecycle(): void
    {
        $config = [
            'corp_id' => 'test_corp',
            'corp_secret' => 'test_secret',
            'encoding_aes_key' => $this->encodingAesKey,
        ];

        Http::fake([
            'qyapi.weixin.qq.com/cgi-bin/gettoken*' => Http::response([
                'errcode' => 0,
                'access_token' => 'test_token',
                'expires_in' => 7200,
            ]),
            'qyapi.weixin.qq.com/cgi-bin/msgaudit/get_chat_data*' => Http::response([
                'errcode' => 0,
                'chatdata' => [
                    ['encrypt_chat_msg' => $this->encryptPayload([
                        'msg_id' => 'msg_lifecycle_001',
                        'room_id' => 'room_lc',
                        'msgtype' => 'text',
                        'from_userid' => 'user1',
                        'seq' => 1,
                        'msgtime' => '1625000000',
                    ])],
                    ['encrypt_chat_msg' => $this->encryptPayload([
                        'msg_id' => 'msg_lifecycle_002',
                        'room_id' => 'room_lc',
                        'msgtype' => 'text',
                        'from_userid' => 'user2',
                        'seq' => 2,
                        'msgtime' => '1625000060',
                    ])],
                ],
                'seq' => 200,
            ]),
        ]);

        $service = new SessionArchiveService($config);

        $fetched = $service->fetchFromApi(0, 100);
        $this->assertCount(2, $fetched['chatdata']);

        $stored = $service->decryptAndStore($fetched['chatdata'], 1001);
        $this->assertSame(2, $stored);

        $messages = $service->queryMessages('room_lc', 10, 0);
        $this->assertCount(2, $messages);

        $stored2 = $service->decryptAndStore($fetched['chatdata'], 1001);
        $this->assertSame(0, $stored2);
    }

    /**
     * 生成 RSA 密钥对并返回 [privatePem, publicPem, encryptRandomKey]（用公钥加密 AES key）。
     */
    private function rsaFixture(): array
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privateKeyPem);
        $details = openssl_pkey_get_details($res);

        $encryptedKey = '';
        openssl_public_encrypt(base64_encode($this->aesKey), $encryptedKey, $details['key']);

        return [$privateKeyPem, base64_encode($encryptedKey)];
    }

    public function test_session_archive_service_decrypt_chat_data_rsa_chain(): void
    {
        [$privateKeyPem, $encryptedKey] = $this->rsaFixture();

        // 走 decryptChatData 链路时无需预配置 encoding_aes_key（AES key 随消息 RSA 下发）
        $service = new SessionArchiveService([
            'corp_id' => 'test_corp',
            'corp_secret' => 'test_secret',
        ]);

        $chatData = [[
            'encrypt_random_key' => $encryptedKey,
            'encrypt_chat_msg' => $this->encryptPayload([
                'msg_id' => 'msg_rsa_001',
                'room_id' => 'room_rsa',
                'msgtype' => 'text',
                'from_userid' => 'user1',
                'seq' => 10,
                'msgtime' => '1625000000000',
            ]),
        ]];

        $messages = $service->decryptChatData($chatData, $privateKeyPem);

        $this->assertCount(1, $messages);
        $this->assertSame('msg_rsa_001', $messages[0]['msg_id']);
        $this->assertSame('room_rsa', $messages[0]['room_id']);
        $this->assertSame('text', $messages[0]['msgtype']);
    }

    public function test_session_archive_service_decrypt_chat_data_wrong_key(): void
    {
        [$privateKeyPem] = $this->rsaFixture();
        [$wrongPrivateKey] = $this->rsaFixture();

        // 用另一对密钥加密（模拟私钥不匹配）
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = openssl_pkey_get_details($res);
        $encryptedKey = '';
        openssl_public_encrypt(base64_encode($this->aesKey), $encryptedKey, $details['key']);

        $service = new SessionArchiveService([
            'corp_id' => 'test_corp',
            'corp_secret' => 'test_secret',
        ]);

        $chatData = [[
            'encrypt_random_key' => base64_encode($encryptedKey),
            'encrypt_chat_msg' => $this->encryptPayload(['msg_id' => 'msg_rsa_002']),
        ]];

        // 私钥不匹配：单条失败不阻断整体，返回空数组且不抛异常
        $messages = $service->decryptChatData($chatData, $wrongPrivateKey);
        $this->assertSame([], $messages);
    }

    public function test_session_archive_service_decrypt_and_store_without_aes_key(): void
    {
        $service = new SessionArchiveService([
            'corp_id' => 'test_corp',
            'corp_secret' => 'test_secret',
        ]);

        $this->assertSame(0, $service->decryptAndStore([
            ['encrypt_chat_msg' => 'encrypted'],
        ], 1001));
    }
}
