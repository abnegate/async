# Utopia Async

[![License](https://img.shields.io/github/license/utopia-php/async.svg)](https://github.com/utopia-php/async/blob/main/LICENSE)

A high-performance async/parallel library for PHP 8.0+ providing JavaScript-like Promises and true multi-core parallel execution using Swoole.

## Features

- **Promise API** - Familiar JavaScript-like promises with `then()`, `catch()`, `finally()`
- **Parallel Execution** - True multi-core parallelism using threads and processes
- **Automatic Adapter Selection** - Chooses optimal strategy based on runtime environment
- **Closure Support** - Execute closures across process boundaries
- **Built-in Safety** - Timeouts, deadlock detection, memory management

## Installation

```bash
composer require utopia-php/async
```

## Requirements

- ext-sockets (for process mode)
- PHP 8.1+ ZTS
- ext-swoole (6.0+, with `--enable-swoole-thread` for thread mode)

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

```php
// Shutdown the default process pool when done
Parallel::shutdown();

// Create a custom pool
$pool = Parallel::createPool(16); // 16 workers
```

## Adapters

The library automatically selects the best adapter based on available extensions.

### Adapter Matrix

| Adapter              | Component | Runtime        | Requirements                                              |
|----------------------|-----------|----------------|-----------------------------------------------------------|
| **Swoole\Coroutine** | Promise   | Async          | `ext-swoole`                                              |
| **Amp**              | Promise   | Async          | `amphp/amp`, `revolt/event-loop`                          |
| **React**            | Promise   | Async          | `react/event-loop`                                        |
| **Sync**             | Promise   | Blocking       | None (fallback)                                           |
| **Swoole\Thread**    | Parallel  | Multi-threaded | `ext-swoole` >=6.0 with threads, PHP ZTS                  |
| **Swoole\Process**   | Parallel  | Multi-process  | `ext-swoole`                                              |
| **Parallel**         | Parallel  | Multi-threaded | `ext-parallel`, PHP ZTS                                   |
| **Amp**              | Parallel  | Multi-process  | `amphp/parallel`                                          |
| **React**            | Parallel  | Multi-process  | `react/child-process`, `react/event-loop`, `opis/closure` |
| **Sync**             | Parallel  | Sequential     | None (fallback)                                           |

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

## Parallel Adapters

Two parallel execution strategies are available:

| Adapter     | Use Case           | Characteristics                      |
|-------------|--------------------|--------------------------------------|
| **Thread**  | CPU-bound tasks    | Shared memory, lower overhead        |
| **Process** | Isolated execution | Full isolation, slower communication |

```php
use Utopia\Async\Parallel;
use Utopia\Async\Parallel\Adapter\Swoole\Thread;
use Utopia\Async\Parallel\Adapter\Swoole\Process;

// Use thread pool (default with Swoole 6+ and ZTS)
Parallel::setAdapter(Thread::class);

// Use process pool
Parallel::setAdapter(Process::class);
```

## Performance

The library achieves significant speedups for CPU-intensive and I/O-bound workloads through true parallel execution.

### Running Benchmarks

Benchmarks automatically detect and test all available adapters in your environment:

```bash
# Quick benchmark - fast comparison of all available adapters
php benchmarks/QuickBenchmark.php

# Comprehensive benchmark - detailed workload analysis
php benchmarks/AdapterBenchmark.php

# Quick mode (shorter tests)
php benchmarks/AdapterBenchmark.php --quick

# Scaling benchmark - task count analysis
php benchmarks/ScalingBenchmark.php

# Custom iteration count for stability
php benchmarks/QuickBenchmark.php --iterations=10

# JSON output for charting
php benchmarks/ScalingBenchmark.php --json
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

### Key Findings

- **Thread-based adapters** (Swoole Thread, ext-parallel) excel at CPU-intensive tasks
- **Process-based adapters** (Swoole Process, Amp, React) perform better for I/O-bound workloads
- Speedup scales with task weight - heavier tasks benefit more from parallelism
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
