<?php

namespace EasySwoole\FastDb;


class Config extends \EasySwoole\Pool\Config
{

    protected string $host;
    protected string $user;
    protected string $password;
    protected string $database;
    protected int $port = 3306;
    protected int $timeout = 5;
    protected string $charset = 'utf8mb4';
    protected int $autoPing = 5;

    protected string $name = "default";

    protected bool $useMysqli = false;

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
     * @return int
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * @param int $timeout
     */
    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
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

    public function isUseMysqli(): bool
    {
        return $this->useMysqli;
    }

    public function setUseMysqli(bool $useMysqli): void
    {
        $this->useMysqli = $useMysqli;
    }
}