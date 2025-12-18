<?php

// ReactPHP worker script

// Find autoloader - try multiple paths
$dir = __DIR__;
$cwd = getcwd();
$autoloadPaths = [
    // From library source (when developing the library)
    $dir . '/../../../vendor/autoload.php',
    // From vendor (when installed as dependency)
    $dir . '/../../../../autoload.php',
    $dir . '/../../../../../autoload.php',
    $dir . '/../../../../../../autoload.php',
    // From CWD
    $cwd . '/vendor/autoload.php',
    $cwd . '/../vendor/autoload.php',
];

$autoloaderFound = false;
foreach ($autoloadPaths as $path) {
    if (\file_exists($path)) {
        require_once $path;
        $autoloaderFound = true;
        break;
    }
}

if (!$autoloaderFound) {
    \fwrite(STDERR, "Autoloader not found\n");
    exit(1);
}

// Read task from stdin
$input = '';
while ($line = fgets(STDIN)) {
    $input .= $line;
}

$lines = explode("\n", trim($input));
if (count($lines) < 2) {
    exit(1);
}

$serializedTask = @base64_decode($lines[0], true);
$serializedArgs = @base64_decode($lines[1], true);

if ($serializedTask === false || $serializedArgs === false) {
    exit(1);
}

try {
    $task = \Opis\Closure\unserialize($serializedTask);
    /** @var array<mixed> $args */
    $args = @unserialize($serializedArgs);

    if (!is_callable($task)) {
        exit(1);
    }

    /** @var array<mixed> $args - validated via unserialize */

    $result = empty($args) ? $task() : $task(...$args);

    $output = serialize(['success' => true, 'result' => $result]);
    echo base64_encode($output);
    exit(0);
} catch (Throwable $e) {
    $output = serialize(['success' => false, 'error' => $e->getMessage()]);
    echo base64_encode($output);
    exit(1);
}
