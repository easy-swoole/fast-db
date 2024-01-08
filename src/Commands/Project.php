<?php
declare(strict_types=1);
/**
 * This file is part of EasySwoole.
 *
 * @link     https://www.easyswoole.com
 * @document https://www.easyswoole.com
 * @contact  https://www.easyswoole.com/Preface/contact.html
 * @license  https://github.com/easy-swoole/easyswoole/blob/3.x/LICENSE
 */

namespace EasySwoole\FastDb\Commands;

use RuntimeException;

class Project
{
    public function getNamespace(string $path): string
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION);

        if ($ext !== '') {
            $path = substr($path, 0, -(strlen($ext) + 1));
        } else {
            $path = trim($path, '/') . '/';
        }

        $namespace = '';
        foreach ($this->getAutoloadRules() as $prefix => $prefixPath) {
            if ($this->isRootNamespace($prefix) || str_starts_with($path, $prefixPath)) {
                $namespace = $prefix . str_replace('/', '\\', substr($path, strlen($prefixPath)));
            }
        }

        if ($namespace) {
            return $namespace;
        }

        throw new RuntimeException("Invalid project path: {$path}");
    }

    public function className(string $path): string
    {
        return $this->getNamespace($path);
    }

    public function path(string $name, $extension = '.php'): string
    {
        if (self::endsWith($name, '\\')) {
            $extension = '';
        }

        $path = '';
        foreach ($this->getAutoloadRules() as $prefix => $prefixPath) {
            if ($this->isRootNamespace($prefix) || str_starts_with($name, $prefix)) {
                $path = $prefixPath . str_replace('\\', '/', substr($name, strlen($prefix))) . $extension;
            }
        }

        if ($path) {
            return $path;
        }

        throw new RuntimeException("Invalid class name: {$name}");
    }

    protected function isRootNamespace(string $namespace): bool
    {
        return $namespace === '';
    }

    protected function getAutoloadRules(): array
    {
        $composerJsonContent = file_get_contents(getcwd() . '/composer.json');
        $composerArrContent = json_decode($composerJsonContent, true);
        return $composerArrContent['autoload']['psr-4'] ?? [];
    }

    private static function endsWith(string $haystack, $needles)
    {
        foreach ((array)$needles as $needle) {
            $needle = (string)$needle;
            if ($needle !== '' && str_ends_with($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
