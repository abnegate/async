# Utopia Async

[![License](https://img.shields.io/github/license/utopia-php/async.svg)](https://github.com/utopia-php/async/blob/main/LICENSE)

A high-performance async/parallel library for PHP 8.1+ providing [Promises/A+](https://promisesaplus.com/) compliant promises and true multi-core parallel execution.

## Features

- **Promise API** - Promises/A+ compliant with `then()`, `catch()`, `finally()`, `await()`
- **Parallel Execution** - True multi-core parallelism using threads and processes
- **Automatic Adapter Selection** - Chooses optimal strategy based on runtime environment
- **Closure Support** - Execute closures across process boundaries
- **Built-in Safety** - Timeouts, deadlock detection, memory management

## Installation

```bash
composer require utopia-php/async
```

## Requirements

- PHP 8.1+

### Optional Extensions (for parallel execution)

| Extension/Package        | Required For                                           |
|--------------------------|--------------------------------------------------------|
| `ext-swoole` >=6.0 + ZTS | Swoole Thread adapter (best performance)               |
| `ext-swoole` + `ext-sockets` | Swoole Process adapter                            |
| `ext-parallel` + ZTS     | ext-parallel adapter                                   |
| `amphp/parallel`         | Amp adapter                                            |
| `react/child-process`    | React adapter                                          |

## Promise API

Promises provide asynchronous operations with coroutine support when Swoole is available, falling back to synchronous execution otherwise.

### Basic Usage

```php
use Utopia\Async\Promise;

// Execute and get result
$result = Promise::run(fn() => 42); // Returns: 42

// Async execution with chaining
Promise::async(fn() => 10)
    ->then(fn($v) => $v * 2)
    ->then(fn($v) => $v + 5)
    ->catch(fn($e) => error_log($e->getMessage()))
    ->finally(fn() => cleanup())
    ->await(); // Returns: 25

// Execute multiple callables concurrently
$results = Promise::map([
    fn() => fetchUser(),
    fn() => fetchPosts(),
    fn() => fetchComments(),
])->await(); // [user, posts, comments]
```

### Promise Methods

```php
// Create resolved/rejected promises
$resolved = Promise::resolve('value');
$rejected = Promise::reject(new Exception('error'));

// Delay execution
Promise::delay(1000)->await(); // Wait 1 second

// Timeout wrapper
Promise::async(fn() => longOperation())
    ->timeout(5000) // 5 second timeout
    ->catch(fn($e) => handleTimeout())
    ->await();
```

### Collection Methods

```php
// Wait for all promises
$results = Promise::all([
    Promise::async(fn() => fetchUser()),
    Promise::async(fn() => fetchPosts()),
    Promise::async(fn() => fetchComments()),
])->await(); // [user, posts, comments]

// First to settle wins
$fastest = Promise::race([
    Promise::async(fn() => primaryApi()),
    Promise::async(fn() => fallbackApi()),
])->await();

// Get all results regardless of success/failure
$results = Promise::allSettled([
    Promise::async(fn() => maySucceed()),
    Promise::async(fn() => mayFail()),
])->await();
// [
//   ['status' => 'fulfilled', 'value' => ...],
//   ['status' => 'rejected', 'reason' => Exception]
// ]

// First successful result
$value = Promise::any([
    Promise::async(fn() => tryFirst()),
    Promise::async(fn() => trySecond()),
])->await();
```

## Parallel API

Parallel execution distributes work across CPU cores using threads or processes.

### Basic Usage

```php
use Utopia\Async\Parallel;

// Single task
$result = Parallel::run(fn() => expensiveCalculation());

// With arguments
$result = Parallel::run(fn($x, $y) => $x + $y, 10, 20); // Returns: 30

// Multiple tasks
$results = Parallel::all([
    fn() => task1(),
    fn() => task2(),
    fn() => task3(),
]); // [result1, result2, result3]
```

### Map and ForEach

```php
$items = [1, 2, 3, 4, 5, 6, 7, 8];

// Parallel map - transforms each item
$squared = Parallel::map($items, fn($n) => $n ** 2);
// [1, 4, 9, 16, 25, 36, 49, 64]

// Specify worker count
$results = Parallel::map($items, fn($n) => process($n), 4); // 4 workers

// ForEach - side effects only, no return values
Parallel::forEach($files, fn($file) => processFile($file), 8);
```

### Pool with Concurrency Control

```php
// Limit concurrent execution
$tasks = [];
for ($i = 0; $i < 100; $i++) {
    $tasks[] = fn() => processItem($i);
}

// Max 10 concurrent tasks
$results = Parallel::pool($tasks, 10);
```

### Lifecycle Management

The default worker pool is automatically cleaned up when the script terminates via `register_shutdown_function()`. You can also manually shutdown early if needed:

```php
// Optional: manually shutdown to release resources early
Parallel::shutdown();

// Create a custom pool (not auto-cleaned, you manage its lifecycle)
$pool = Parallel::createPool(16); // 16 workers
```

## Adapters

The library automatically selects the best adapter based on available extensions.

### Promise Adapter Priority

| Priority | Adapter              | Runtime  | Requirements                     |
|----------|----------------------|----------|----------------------------------|
| 1        | **Swoole\Coroutine** | Async    | `ext-swoole`                     |
| 2        | **React**            | Async    | `react/event-loop`               |
| 3        | **Amp**              | Async    | `amphp/amp`, `revolt/event-loop` |
| 4        | **Sync**             | Blocking | None (fallback)                  |

### Parallel Adapter Priority

| Priority | Adapter              | Runtime        | Requirements                                              |
|----------|----------------------|----------------|-----------------------------------------------------------|
| 1        | **Swoole\Thread**    | Multi-threaded | `ext-swoole` >=6.0 with threads, PHP ZTS                  |
| 2        | **ext-parallel**     | Multi-threaded | `ext-parallel`, PHP ZTS                                   |
| 3        | **Swoole\Process**   | Multi-process  | `ext-swoole`, `ext-sockets`                               |
| 4        | **React**            | Multi-process  | `react/child-process`, `react/event-loop`, `opis/closure` |
| 5        | **Amp**              | Multi-process  | `amphp/parallel`                                          |
| 6        | **Sync**             | Sequential     | None (fallback)                                           |

### Manual Adapter Selection

```php
use Utopia\Async\Promise;
use Utopia\Async\Promise\Adapter\Sync;

Promise::setAdapter(Sync::class);
```

## Exception Handling

```php
use Utopia\Async\Exception\Timeout;
use Utopia\Async\Exception\Serialization;

try {
    Promise::async(fn() => riskyOperation())
        ->timeout(5000)
        ->await();
} catch (Timeout $e) {
    // Handle timeout
} catch (Serialization $e) {
    // Handle serialization failure
}
```

## Configuration

Both `Parallel` and `Promise` facades expose configurable options via static getter/setter methods.

### Parallel Configuration

```php
use Utopia\Async\Parallel;

// Get current values
Parallel::getMaxSerializedSize();         // 10 MB (10485760 bytes)
Parallel::getMaxTaskTimeoutSeconds();     // 30 seconds
Parallel::getDeadlockDetectionInterval(); // 5 seconds
Parallel::getMemoryThresholdForGc();      // 50 MB (52428800 bytes)
Parallel::getStreamSelectTimeoutUs();     // 100ms (100000 μs)
Parallel::getWorkerSleepDurationUs();     // 10ms (10000 μs)
Parallel::getGcCheckInterval();           // 10 tasks

// Set custom values
Parallel::setMaxTaskTimeoutSeconds(60);  // Increase timeout to 60s
Parallel::setMemoryThresholdForGc(104857600); // 100 MB

// Reset all to defaults
Parallel::resetConfig();
```

| Option                      | Default | Description                               |
|-----------------------------|---------|-------------------------------------------|
| `MaxSerializedSize`         | 10 MB   | Maximum payload size for task data        |
| `MaxTaskTimeoutSeconds`     | 30s     | Task execution timeout                    |
| `DeadlockDetectionInterval` | 5s      | Progress check interval for stuck workers |
| `MemoryThresholdForGc`      | 50 MB   | GC trigger threshold                      |
| `StreamSelectTimeoutUs`     | 100ms   | Non-blocking I/O timeout                  |
| `WorkerSleepDurationUs`     | 10ms    | Worker idle sleep duration                |
| `GcCheckInterval`           | 10      | Tasks between GC checks                   |

### Promise Configuration

```php
use Utopia\Async\Promise;

// Get current values
Promise::getSleepDurationUs();           // 100 μs
Promise::getMaxSleepDurationUs();        // 10ms (10000 μs)
Promise::getCoroutineSleepDurationS();   // 1ms (0.001 seconds)

// Set custom values
Promise::setSleepDurationUs(200);        // Increase initial sleep
Promise::setMaxSleepDurationUs(50000);   // 50ms max backoff

// Reset all to defaults
Promise::resetConfig();
```

| Option                    | Default | Description                           |
|---------------------------|---------|---------------------------------------|
| `SleepDurationUs`         | 100μs   | Initial sleep for exponential backoff |
| `MaxSleepDurationUs`      | 10ms    | Maximum sleep duration (backoff cap)  |
| `CoroutineSleepDurationS` | 1ms     | Coroutine context sleep duration      |

## Performance

The library achieves significant speedups for CPU-intensive and I/O-bound workloads through true parallel execution.

### Running Benchmarks

Benchmarks automatically detect and test all available adapters in your environment:

```bash
# Run benchmarks with default settings (5 iterations, 50% load)
php benchmarks/Benchmark.php

# Higher iteration count for more stable results
php benchmarks/Benchmark.php --iterations=10

# Adjust workload intensity (1-100, default 50)
php benchmarks/Benchmark.php --load=75

# JSON output for charting and analysis
php benchmarks/Benchmark.php --json

# Combined options
php benchmarks/Benchmark.php --iterations=10 --load=75 --json
```

### Adapter Comparison

| Adapter            | Type           | Best For        | Characteristics                |
|--------------------|----------------|-----------------|--------------------------------|
| **Swoole Thread**  | Multi-threaded | CPU-bound tasks | Lowest overhead, shared memory |
| **Swoole Process** | Multi-process  | I/O-bound tasks | Full isolation, blocking ops   |
| **Amp**            | Multi-process  | Async I/O       | Event-loop based, fibers       |
| **React**          | Multi-process  | Async I/O       | Event-loop based               |
| **ext-parallel**   | Multi-threaded | CPU-bound tasks | Native PHP threads             |
| **Sync**           | Sequential     | Fallback        | Always available               |

### Benchmark Results

Results from running benchmarks with `--iterations=10 --load=75` on an 8-core system:

**CPU-Intensive Workloads (8 tasks)**

| Benchmark | Sync | Swoole Thread | Swoole Process | Amp | React |
|-----------|------|---------------|----------------|-----|-------|
| Prime calculation (300k) | 0.608s | 0.096s (6.4x) | 0.093s (6.6x) | 0.095s (6.4x) | 0.146s (4.2x) |
| Matrix multiply (300x300) | 2.957s | 0.655s (4.5x) | 0.494s (6.0x) | 0.504s (5.9x) | 0.921s (3.2x) |

**I/O-Simulated Workloads (8 tasks)**

| Benchmark | Sync | Swoole Thread | Swoole Process | Amp | React |
|-----------|------|---------------|----------------|-----|-------|
| Sleep tasks (75ms each) | 0.609s | 0.089s (6.8x) | 0.089s (6.9x) | 0.091s (6.7x) | 0.103s (5.9x) |
| Mixed workload | 0.367s | 0.119s (3.1x) | 0.079s (4.6x) | 0.087s (4.2x) | 0.105s (3.5x) |

**ext-parallel Benchmark Results (4-core system)**

| Benchmark | Sync | ext-parallel | Amp | React |
|-----------|------|--------------|-----|-------|
| Prime calculation (300k) | 0.307s | 0.082s (3.8x) | 0.087s (3.5x) | 0.098s (3.1x) |
| Matrix multiply (300x300) | 1.453s | 0.386s (3.8x) | 0.380s (3.8x) | 0.394s (3.7x) |
| Sleep tasks (75ms each) | 0.305s | 0.084s (3.7x) | 0.087s (3.5x) | 0.106s (2.9x) |

### Key Findings

- **Swoole Process** achieves the best overall performance with up to **6.9x speedup** on I/O workloads
- **Swoole Thread** and **Amp** show comparable performance on CPU-intensive tasks (~6x speedup)
- **ext-parallel** excels at CPU-bound tasks, achieving **3.8x speedup** on a 4-core system
- **React** provides solid multi-process execution but has higher overhead than other adapters
- Parallelism overhead means single-task workloads may be slower than sequential execution
- Results vary by environment - run benchmarks to find the best adapter for your use case

## Development

```bash
# Run tests
composer test

# Static analysis
composer check

# Code formatting
composer format
```

## License

MIT License. See [LICENSE](LICENSE) for details.
