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

The library automatically selects the best adapter:

| Component | With Swoole     | Without Swoole  |
|-----------|-----------------|-----------------|
| Promise   | Coroutine       | Sync (blocking) |
| Parallel  | Thread, Process | Sync (blocking) |

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

## Configuration Constants

Located in `Parallel\Constants`:

| Constant                      | Default | Description             |
|-------------------------------|---------|-------------------------|
| `MAX_SERIALIZED_SIZE`         | 10 MB   | Maximum payload size    |
| `MAX_TASK_TIMEOUT_SECONDS`    | 30s     | Task execution timeout  |
| `DEADLOCK_DETECTION_INTERVAL` | 5s      | Progress check interval |
| `MEMORY_THRESHOLD_FOR_GC`     | 50 MB   | GC trigger threshold    |

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

### Benchmark Results (8 CPU cores, Swoole 6.1, 5 iterations averaged)

| Workload | Sync | Thread | Process | Best Speedup |
|----------|------|--------|---------|--------------|
| Prime calculation (8 tasks) | 0.099s | 0.021s | 0.036s | **4.6x** (Thread) |
| Matrix multiply (8 tasks) | 0.332s | 0.018s | 0.064s | **18.5x** (Thread) |
| Sleep 50ms × 8 tasks | 0.420s | 0.081s | 0.068s | **6.1x** (Process) |
| Mixed CPU/IO (8 tasks) | 0.243s | 0.077s | 0.066s | **3.7x** (Process) |

### Adapter Comparison

| Adapter | Best For | Avg Speedup | Characteristics |
|---------|----------|-------------|-----------------|
| **Thread** | CPU-bound tasks | 6.4x | Lower overhead, shared memory |
| **Process** | I/O-bound tasks | 4.4x | Full isolation, better for blocking ops |

### Running Benchmarks

```bash
# Quick benchmark (fastest)
php benchmarks/QuickBenchmark.php

# Comprehensive benchmark
php benchmarks/AdapterBenchmark.php

# Quick mode (shorter tests)
php benchmarks/AdapterBenchmark.php --quick

# Scaling benchmark (task count analysis)
php benchmarks/ScalingBenchmark.php

# Custom iteration count for stability
php benchmarks/QuickBenchmark.php --iterations=10

# JSON output for charting
php benchmarks/ScalingBenchmark.php --json
```

### Key Findings

- **Thread adapter** excels at CPU-intensive tasks with up to 18x speedup
- **Process adapter** performs better for I/O-bound and mixed workloads
- Speedup scales with task weight - heavier tasks benefit more from parallelism
- Both adapters converge to similar performance at high task counts

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

BSD-3-Clause License. See [LICENSE](LICENSE) for details.
