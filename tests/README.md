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
