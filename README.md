# Async

A simple PHP library for creating asynchronous processes, and handling inter-process communication via sockets.

## Installation

```sh
composer require superrb/async
```

## Usage

### Getting Started

The easiest way to use this package is to use the `async` helper. This simply runs the supplied closure asynchronously within a forked process. The closure must return a boolean to indicate success/failure of the subprocess, and have the return type `bool`, as this is used to set the exit state of the process.

```php
echo 1."\n";

async(function(): bool {
	sleep(1);
	echo 2."\n";
	return true;
});

echo 3."\n";

// Output
1
3
2
```

Any arguments passed to `async` will be passed straight to the forked process.

```php
async(function($i, $j): bool {
	echo $i."\n";
	echo $j."\n";
	return true;
}, 1, 2);

// Output
1
2
```

### Reusable closures

You can reuse a closure, by manually constructing an instance of `Superrb\Async\Handler`

```php
$handler = new Superrb\Async\Handler(function(int $i): bool {
	echo $i;
	return true;
});

for ($i = 0; $i < 10; $i++) {
	$handler->run($i);
}

// Processing will pause here until all asynchronous processes
// have completed. $success is true if all processes returned
// true, otherwise it is false
$success = $handler->waitAll();
```

You can run synchronous code within forked processes by passing `false` as the second argument to the `Handler` constructor. This is useful for running long processes such as imports, as any memory consumed within the loop is dumped at the end of the process.

```php
$handler = new Superrb\Async\Handler(function(int $i): bool {
	echo $i;
	return true;
}, false);

for ($i = 0; $i < 10; $i++) {
	// The loop will pause whilst the process runs, and continue
	// when it is completed. $success is the return value of the
	// closure
	$success = $handler->run($i);
}
```

### Inter-process communication

#### Sending messages to the parent

`async` uses channels and socket pairs to allow for communication between the child and parent process. Communication is one way - you can pass messages from the child process back to the main process using `$this->send()` within your closure. Messages can be strings, objects or arrays.

```php
$handler = new Superrb\Async\Handler(function(int $i): bool {
	$this->send('Hi from process '.$i);
	return true;
});

for ($i = 0; $i < 10; $i++) {
	$handler->run($i);
}

$handler->waitAll();
```

#### Reading messages

Messages can then be read by calling the generator `$handler->getMessages()` once processing has completed. For asynchronous processes, the messages will always be received **in the order the handlers were run** regardless of which process finished first.

```php
foreach($handler->getMessages() as $message) {
	echo $message."\n";
}

// output
Hi from process 1
Hi from process 2
Hi from process 3
...
```

For synchronous processes, you can read messages at the end of each process. If you do this, you'll need to clear the message pool manually after reading them.

```php
for ($i = 0; $i < 10; $i++) {
	$handler->run($i);
	
	foreach($handler->getMessages() as $message) {
		echo $message."\n";
	}
	
	$handler->clearMessages();
}
```

#### Increasing the communication buffer

By default messages are sent within a 1024-byte message buffer, and attempting to send data larger than this will result in an exception. You can increase/decrease the buffer by calling `setMessageBuffer` on the handler.

```php
$handler->setMessageBuffer(4096);
```

## Contributing

All contributions are welcome, and encouraged. Please read our [contribution guidelines](CONTRIBUTING.md) and [code of conduct](CODE-OF-CONDUCT.md) for more information.

## License

Copyright (c) 2017 [Superrb Studio](https://superrb.com) <tech@superrb.com>

Async is licensed under The MIT License (MIT)

## Team

* James Dinsdale (@molovo)
