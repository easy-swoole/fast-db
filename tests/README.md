# Tests

Development tests use PHPUnit 13.4 and require PHP 8.4.1 or newer, Swoole and the native mysqli extension. Native mysqli is a development-only dependency used by independent observer connections in transaction tests; production queries use the mysqli 5.x coroutine protocol client. Install dependencies with `composer install`.

Run unit tests without a database:

```sh
composer test:unit
```

Integration tests create tables and truncate/write test data. Use a dedicated test database. Set the database connection through environment variables (no credentials are committed):

```sh
export FAST_DB_TEST_HOST=127.0.0.1
export FAST_DB_TEST_PORT=3306
export FAST_DB_TEST_USER=test
export FAST_DB_TEST_DATABASE=test
read -s FAST_DB_TEST_PASSWORD
export FAST_DB_TEST_PASSWORD
composer test
```

`composer test:integration` runs only database tests. Additional PHPUnit options can be passed to the coroutine runner:

```sh
php tests/run.php --filter QueryTimingTest --display-all-issues
```

`tests/run.php` uses PHPUnit's current `TextUI\Application` inside a Swoole coroutine. `vendor/bin/phpunit --testsuite unit` also works directly; use the coroutine runner for integration tests.


Real-server transaction and timeout regression:

```sh
php tests/run.php --filter TransactionTest --display-all-issues
```

These tests cover commit/rollback visibility, CHAIN/RELEASE, row-lock client timeout, server lock-wait error 1205, deadlock 1213, pool acquisition timeout and recovery, raw/prepared query timeout, and lost-transaction recycling. A local TCP proxy forwards transactions to the configured real server, then delays their responses to verify begin/commit/rollback deadlines and the case where the server commits before the client times out.

Bounded concurrency benchmark (uses the same environment variables):

```sh
php tests/benchmark.php ./benchmark-results.json
php tests/benchmark.php ./soak-results.json --soak
```

The default benchmark runs raw reads, prepared reads, independent-row transactions and transactions contending on one row at 1/8/32/64 workers. The pool is capped at 32 connections; 64 workers exercise connection-pool waiting. Each operation releases its connection via invoke. Transactions mix commits and rollbacks, and the final sum is verified against the successful commit count. The soak run executes 16,000 transactions at 64 workers. Both modes create a uniquely named InnoDB table and drop it in finally.

JSON reports operation throughput, business-SQL throughput (excluding pool health checks and protocol commands), successful-operation p50/p95/p99 latency, errors, data consistency, created connections and remaining active contexts. Latency includes connection acquisition and health checks; pool prewarming is excluded. These are bounded client-side measurements over the configured network path, not maximum server capacity or a long-duration soak test. Peak PHP memory includes retained latency samples.

## GitHub Actions 自动测试

每次 push、Pull Request 和手动触发都会运行 `.github/workflows/tests.yml`，同一分支的新运行会取消尚未完成的旧运行。

| 环境 | 检查范围 |
| --- | --- |
| PHP 8.1.34 / Swoole 5.1.8 | 生产依赖安装、平台要求、核心类加载及 src 语法检查 |
| PHP 8.4.27 / Swoole 6.2.4 / MySQL 8.0.46 | 完整单元测试和集成测试 |
| PHP 8.4.27 / Swoole 6.2.4 / MySQL 8.4.13 | 完整单元测试和集成测试 |
| PHP 8.4.27 / Swoole 6.2.4 / MySQL 9.7.4 | 完整单元测试和集成测试 |

PHPUnit 13 要求 PHP 8.4.1 或更高版本，因此最低运行时任务不执行 PHPUnit，不能据此认为 PHP 8.1 已运行完整测试套件。

各数据库任务通过 Docker Compose 创建独立 MySQL 和数据卷，等待测试账号可实际执行 SELECT 后运行测试。端口不映射到宿主机，测试账号只用于这些临时容器，无需配置 GitHub Secrets。完整套件包含会建表和清空固定测试表的用例，因此始终使用容器内的专用 `fastdb_ci` 库。

源码只读挂载，Composer 和 PHPUnit 使用容器内临时工作区；结束后清理容器及数据卷。运行日志、PHP/Swoole 及依赖版本、JUnit XML 保留在 Actions artifacts 中，保存 7 天。测试缺少运行条件而跳过时，CI 也会失败。

### 本地复现 CI 数据库任务

需要已运行的 Docker Engine / Docker Desktop 和 Docker Compose：

```sh
mkdir -p tests/docker/results
docker compose -f tests/docker/compose.yaml up --build --exit-code-from tests
# 失败时先查看容器日志，再清理本次测试库：
docker compose -f tests/docker/compose.yaml logs --no-color
docker compose -f tests/docker/compose.yaml down --volumes --remove-orphans
```

默认使用 MySQL 8.4.13。执行其他版本时，在上述命令的整个执行期间保持同一个 `MYSQL_VERSION`，例如先 `export MYSQL_VERSION=8.0.46`。并行运行不同版本时分别设置不同的 `COMPOSE_PROJECT_NAME`，清理时沿用对应名称。

### 更新测试依赖

根目录 composer.lock 不跟踪；`tests/docker/dependencies.lock.json` 是 CI 专用锁文件快照，不影响库使用者解析依赖。修改 composer.json 或主动升级测试依赖后，在 PHP 8.4 的一次性工作区重新解析并验证依赖，将生成的 composer.lock 更新到这个快照，随后运行上述矩阵。不要手工修改锁文件中的版本或 content-hash。
