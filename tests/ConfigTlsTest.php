<?php
declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Config;
use EasySwoole\Mysqli\Config as DriverConfig;
use EasySwoole\Mysqli\TlsMode;
use EasySwoole\Mysqli\TlsVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigTlsTest extends TestCase
{
    public function testDefaultConfigurationKeepsTlsDisabled(): void
    {
        $config = new Config();
        $driver = new DriverConfig($config->toArray());
        $this->assertSame('disabled', $config->getTlsMode());
        $this->assertSame(TlsMode::Disabled, $driver->getTlsMode());
        $this->assertSame(['TLSv1.2','TLSv1.3'], $driver->getTlsVersions());
        foreach (['tlsCaFile','tlsCaPath','tlsCertFile','tlsKeyFile','tlsKeyPassphrase','tlsServerName'] as $name) {
            $this->assertSame('', $config->toArray()[$name]);
        }
    }

    public static function modes(): array
    {
        $cases = [];
        foreach (TlsMode::cases() as $mode) {
            $cases[$mode->value . '-enum'] = [$mode,$mode];
            $cases[$mode->value . '-string'] = [$mode->value,$mode];
        }
        return $cases;
    }

    #[DataProvider('modes')]
    public function testConstructorAndSerializationPassAllTlsSettingsToTheDriver(TlsMode|string $mode, TlsMode $expected): void
    {
        $settings = ['tlsMode'=>$mode, 'tlsCaFile'=>'/certs/ca.pem', 'tlsCaPath'=>'/certs/ca',
            'tlsCertFile'=>'/certs/client.pem', 'tlsKeyFile'=>'/certs/client.key',
            'tlsKeyPassphrase'=>'test-only-passphrase', 'tlsServerName'=>'mysql.internal.example',
            'tlsVersions'=>[TlsVersion::Tls12,'TLSv1.3']];
        $config = new Config($settings + ['name'=>'secured', 'maxObjectNum'=>4]);
        $exported = $config->toArray();
        $this->assertSame($settings, array_intersect_key($exported, $settings));
        $this->assertSame($mode, $config->getTlsMode());
        $this->assertSame('secured', $exported['name']);
        $this->assertSame(4, $exported['maxObjectNum']);
        // 连接池和 testDb 都通过这条配置转换路径创建驱动配置，无需重复 TLS 解析。
        $driver = new DriverConfig($exported);
        $this->assertSame($expected, $driver->getTlsMode());
        $this->assertSame('/certs/ca.pem', $driver->getTlsCaFile());
        $this->assertSame('/certs/ca', $driver->getTlsCaPath());
        $this->assertSame('/certs/client.pem', $driver->getTlsCertFile());
        $this->assertSame('/certs/client.key', $driver->getTlsKeyFile());
        $this->assertSame('test-only-passphrase', $driver->getTlsKeyPassphrase());
        $this->assertSame('mysql.internal.example', $driver->getTlsServerName());
        $this->assertSame(['TLSv1.2','TLSv1.3'], $driver->getTlsVersions());
    }

    public function testSettersAndRestoreKeepTheDriverConfigurationInSync(): void
    {
        $config = new Config();
        $config->setTlsMode(TlsMode::VerifyIdentity);
        $config->setTlsCaFile('/certs/ca.pem');
        $config->setTlsCaPath('/certs/ca');
        $config->setTlsCertFile('/certs/client.pem');
        $config->setTlsKeyFile('/certs/client.key');
        $config->setTlsKeyPassphrase('test-only-passphrase');
        $config->setTlsServerName('mysql.internal.example');
        $config->setTlsVersions([TlsVersion::Tls13]);
        $this->assertSame(TlsMode::VerifyIdentity, $config->getTlsMode());
        $this->assertSame([TlsVersion::Tls13], $config->getTlsVersions());
        $this->assertSame('/certs/ca.pem', $config->getTlsCaFile());
        $this->assertSame('/certs/ca', $config->getTlsCaPath());
        $this->assertSame('/certs/client.pem', $config->getTlsCertFile());
        $this->assertSame('/certs/client.key', $config->getTlsKeyFile());
        $this->assertSame('test-only-passphrase', $config->getTlsKeyPassphrase());
        $this->assertSame('mysql.internal.example', $config->getTlsServerName());
        $driver = new DriverConfig($config->toArray());
        $this->assertSame(TlsMode::VerifyIdentity, $driver->getTlsMode());
        $this->assertSame(['TLSv1.3'], $driver->getTlsVersions());
        $config->restore(['tlsMode'=>'verify_ca', 'tlsServerName'=>'replica.internal.example']);
        $driver = new DriverConfig($config->toArray());
        $this->assertSame(TlsMode::VerifyCA, $driver->getTlsMode());
        $this->assertSame('replica.internal.example', $driver->getTlsServerName());
        $this->assertSame('/certs/ca.pem', $driver->getTlsCaFile());
        $this->assertSame(['TLSv1.3'], $driver->getTlsVersions());
    }
}
