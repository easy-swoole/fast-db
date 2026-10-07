# Tests

Development tests use PHPUnit 13.4 and require PHP 8.4.1 or newer, mysqli and Swoole. Install dependencies with `composer install`.

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
