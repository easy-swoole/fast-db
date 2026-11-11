#!/usr/bin/env bash
set -euo pipefail

mode=${1:-test}
case "$mode" in
    test) dependency_options=() ;;
    production) dependency_options=(--no-dev) ;;
    *) echo 'Usage: run-tests.sh [test|production]' >&2; exit 2 ;;
esac

mkdir -p /workspace/project /results
# 不写入宿主机源码、vendor 或 composer.lock。
tar -C /source --exclude='./.git' --exclude='./vendor' \
    --exclude='./composer.lock' --exclude='./.phpunit.cache' \
    --exclude='./tests/docker/results' -cf - . | tar -C /workspace/project -xf -
cd /workspace/project
cp tests/docker/dependencies.lock.json composer.lock
composer validate --no-check-publish --check-lock
composer install --prefer-dist --no-interaction --no-progress --no-scripts "${dependency_options[@]}"
composer check-platform-reqs "${dependency_options[@]}"

php <<'PHP' | tee /results/runtime.json
<?php
require 'vendor/autoload.php';
foreach ([EasySwoole\FastDb\FastDb::class, EasySwoole\FastDb\AbstractInterface\AbstractEntity::class,
    EasySwoole\FastDb\Mysql\Connection::class, EasySwoole\FastDb\Mysql\Pool::class] as $class) {
    if (!class_exists($class)) { throw new RuntimeException('Unable to load ' . $class); }
}
echo json_encode(['php'=>PHP_VERSION, 'swoole'=>phpversion('swoole'),
    'mysqli'=>Composer\InstalledVersions::getPrettyVersion('easyswoole/mysqli'),
    'spl'=>Composer\InstalledVersions::getPrettyVersion('easyswoole/spl')], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
PHP

find src -type f -name '*.php' -print0 | xargs -0 -n 1 php -l
if [ "$mode" = production ]; then exit 0; fi

# 保持与本地相同的协程测试入口；缺失运行条件造成的跳过也应使 CI 失败。
php tests/run.php --testsuite unit --fail-on-skipped --do-not-record-test-run-history \
    --log-junit /results/unit.xml
php tests/run.php --testsuite integration --fail-on-skipped --do-not-record-test-run-history \
    --log-junit /results/integration.xml
