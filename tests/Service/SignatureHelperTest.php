<?php

declare(strict_types=1);

namespace Emporiqa\SyliusPlugin\Tests\Service;

use Emporiqa\SyliusPlugin\Service\SignatureHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SignatureHelper against Emporiqa's scheme-2 vectors
 * (tests/fixtures/signature_vectors.json, byte-identical to the platform's
 * copy): every key, signature and header per label must match, every
 * negative vector must be refused, and a rule call signed during a secret
 * change (two v1 values) must verify holding either secret.
 */
class SignatureHelperTest extends TestCase
{
    private const LABELS = [
        SignatureHelper::LABEL_INBOUND,
        SignatureHelper::LABEL_OUTBOUND,
        SignatureHelper::LABEL_RESPONSE,
    ];

    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../fixtures/signature_vectors.json'), true);
    }

    public static function vectors(): iterable
    {
        foreach (self::fixture()['vectors'] as $i => $vector) {
            yield 'vector ' . $i . ' ' . $vector['label'] => [$vector];
        }
    }

    public static function negativeVectors(): iterable
    {
        foreach (self::fixture()['negative_vectors'] as $vector) {
            // Refused by Emporiqa's single-v1 webhook verifier; ours accepts several.
            if (empty($vector['single_v1_only'])) {
                yield $vector['case'] => [$vector];
            }
        }
    }

    public static function rotationVectors(): iterable
    {
        foreach (self::fixture()['rotation_vectors'] as $vector) {
            foreach ($vector['verify_with'] as $secret) {
                yield $vector['case'] . ' / ' . substr($secret, 0, 12) => [$vector, $secret];
            }
        }
    }

    public function testSaltMatchesThePlatform(): void
    {
        $this->assertSame(self::fixture()['salt'], SignatureHelper::SALT);
    }

    #[DataProvider('vectors')]
    public function testVectorKeySignatureAndHeader(array $v): void
    {
        $message = $v['label'] === SignatureHelper::LABEL_RESPONSE ? $v['request_id'] . '.' . $v['body'] : $v['body'];
        $key = SignatureHelper::deriveKey($v['secret'], $v['label'], $v['store_id']);

        $this->assertSame($v['expected_key_hex'], bin2hex($key));
        $header = SignatureHelper::buildHeader($key, $message, $v['t']);
        $this->assertSame($v['expected_header'], $header);
        $this->assertSame('ok', SignatureHelper::verifyHeader($header, $message, $v['secret'], $v['store_id'], $v['label'], $v['t']));
        $this->assertSame('ok', SignatureHelper::verifyHeader($header, $message, $v['secret'], $v['store_id'], $v['label'], $v['t'] - 300));
        $this->assertSame('expired', SignatureHelper::verifyHeader($header, $message, $v['secret'], $v['store_id'], $v['label'], $v['t'] + 301));
        foreach (array_diff(self::LABELS, [$v['label']]) as $other) {
            $this->assertSame('signature', SignatureHelper::verifyHeader($header, $message, $v['secret'], $v['store_id'], $other, $v['t']));
        }
    }

    #[DataProvider('negativeVectors')]
    public function testNegativeVectorIsRefused(array $v): void
    {
        $this->assertNotSame('ok', SignatureHelper::verifyHeader(
            $v['header'],
            $v['body'],
            $v['secret'],
            $v['store_id'],
            SignatureHelper::LABEL_INBOUND,
            $v['now'],
        ));
    }

    #[DataProvider('rotationVectors')]
    public function testRotationVector(array $v, string $secret): void
    {
        $this->assertSame($v['expect'], SignatureHelper::verifyHeader($v['header'], $v['body'], $secret, $v['store_id'], $v['label'], $v['t']));
    }

    public function testTwoSignatureHeaderStillExpires(): void
    {
        $v = self::fixture()['rotation_vectors'][0];

        $this->assertSame('expired', SignatureHelper::verifyHeader($v['header'], $v['body'], $v['verify_with'][0], $v['store_id'], $v['label'], $v['t'] + 301));
    }

    public function testMoreThanThreeSignaturesAreRefused(): void
    {
        $key = SignatureHelper::deriveKey('s', SignatureHelper::LABEL_OUTBOUND, 'st');
        $header = SignatureHelper::buildHeader($key, 'b', 100);
        $padding = ',v1=' . str_repeat('a', 64);

        $this->assertSame('ok', SignatureHelper::verifyHeader($header . $padding . $padding, 'b', 's', 'st', SignatureHelper::LABEL_OUTBOUND, 100));
        $this->assertSame('signature', SignatureHelper::verifyHeader($header . $padding . $padding . $padding, 'b', 's', 'st', SignatureHelper::LABEL_OUTBOUND, 100));
    }

    public function testEmptySecretOrStoreIdNeverVerifies(): void
    {
        $key = SignatureHelper::deriveKey('s', SignatureHelper::LABEL_OUTBOUND, 'st');
        $header = SignatureHelper::buildHeader($key, 'b', 100);

        $this->assertSame('signature', SignatureHelper::verifyHeader($header, 'b', '', 'st', SignatureHelper::LABEL_OUTBOUND, 100));
        $this->assertSame('signature', SignatureHelper::verifyHeader($header, 'b', 's', '', SignatureHelper::LABEL_OUTBOUND, 100));
    }

    public function testCustomerTokenCarriesAudAndIntegerTs(): void
    {
        $token = SignatureHelper::customerToken('77', 'secret', 'st_7Kq2mXa9');
        [$encoded, $mac] = explode('.', $token);
        $claims = json_decode(base64_decode(strtr($encoded, '-_', '+/')), true);

        $this->assertSame(hash_hmac('sha256', $encoded, 'secret'), $mac);
        $this->assertSame('77', $claims['uid']);
        $this->assertSame('st_7Kq2mXa9', $claims['aud']);
        $this->assertIsInt($claims['ts']);
        $this->assertLessThan(5, abs($claims['ts'] - time()));
        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $encoded);
    }
}
