<?php

namespace EasySwoole\FastDb;

use EasySwoole\Mysqli\TlsMode;
use EasySwoole\Mysqli\TlsVersion;

class Config extends \EasySwoole\Pool\Config
{

    protected string $host = '127.0.0.1';
    protected string $user = '';
    protected string $password = '';
    protected string $database = '';
    protected int $port = 3306;
    protected string $charset = 'utf8mb4';
    protected int $autoPing = 5;
    protected float $timeout = 3.0;
    protected float $maxConnectTime = 5.0;
    protected bool $compress = false;

    // TLS 参数原样传给 mysqli，模式解析、证书校验和握手由驱动负责。
    protected TlsMode|string $tlsMode = 'disabled';
    protected string $tlsCaFile = '';
    protected string $tlsCaPath = '';
    protected string $tlsCertFile = '';
    protected string $tlsKeyFile = '';
    protected string $tlsKeyPassphrase = '';
    protected string $tlsServerName = '';
    protected array $tlsVersions = ['TLSv1.2', 'TLSv1.3'];

    protected string $name = "default";

    protected bool $isForceRollback = false;

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * @param string $host
     */
    public function setHost(string $host): void
    {
        $this->host = $host;
    }

    /**
     * @return string
     */
    public function getUser(): string
    {
        return $this->user;
    }

    /**
     * @param string $user
     */
    public function setUser(string $user): void
    {
        $this->user = $user;
    }

    /**
     * @return string
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * @param string $password
     */
    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    /**
     * @return string
     */
    public function getDatabase(): string
    {
        return $this->database;
    }

    /**
     * @param string $database
     */
    public function setDatabase(string $database): void
    {
        $this->database = $database;
    }

    /**
     * @return int
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * @param int $port
     */
    public function setPort(int $port): void
    {
        $this->port = $port;
    }

    /**
     * @return string
     */
    public function getCharset(): string
    {
        return $this->charset;
    }

    /**
     * @param string $charset
     */
    public function setCharset(string $charset): void
    {
        $this->charset = $charset;
    }

    /**
     * @return int
     */
    public function getAutoPing(): int
    {
        return $this->autoPing;
    }

    /**
     * @param int $autoPing
     */
    public function setAutoPing(int $autoPing): void
    {
        $this->autoPing = $autoPing;
    }

    public function getTimeout(): float { return $this->timeout; }
    public function setTimeout(float $timeout): void { $this->timeout = $timeout; }
    public function getMaxConnectTime(): float { return $this->maxConnectTime; }
    public function setMaxConnectTime(float $timeout): void { $this->maxConnectTime = $timeout; }
    public function isCompress(): bool { return $this->compress; }
    public function setCompress(bool $compress): void { $this->compress = $compress; }

    public function getTlsMode(): TlsMode|string { return $this->tlsMode; }
    public function setTlsMode(TlsMode|string $mode): void { $this->tlsMode = $mode; }

    public function getTlsCaFile(): string { return $this->tlsCaFile; }
    public function setTlsCaFile(string $value): void { $this->tlsCaFile = $value; }

    public function getTlsCaPath(): string { return $this->tlsCaPath; }
    public function setTlsCaPath(string $value): void { $this->tlsCaPath = $value; }

    public function getTlsCertFile(): string { return $this->tlsCertFile; }
    public function setTlsCertFile(string $value): void { $this->tlsCertFile = $value; }

    public function getTlsKeyFile(): string { return $this->tlsKeyFile; }
    public function setTlsKeyFile(string $value): void { $this->tlsKeyFile = $value; }

    public function getTlsKeyPassphrase(): string { return $this->tlsKeyPassphrase; }
    public function setTlsKeyPassphrase(string $value): void { $this->tlsKeyPassphrase = $value; }

    public function getTlsServerName(): string { return $this->tlsServerName; }
    public function setTlsServerName(string $value): void { $this->tlsServerName = $value; }

    /** @return array<array-key, TlsVersion|string> 保留传入的枚举或字符串。 */
    public function getTlsVersions(): array { return $this->tlsVersions; }
    /** @param array<array-key, TlsVersion|string> $versions */
    public function setTlsVersions(array $versions): void { $this->tlsVersions = $versions; }

    public function isForceRollback(): bool
    {
        return $this->isForceRollback;
    }

    public function setForceRollback(bool $enabled): void
    {
        $this->isForceRollback = $enabled;
    }
}
