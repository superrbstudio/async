<?php

namespace Superrb\Async;

use BadMethodCallException;
use Closure;
use Generator;
use ReflectionFunction;
use RuntimeException;

class Handler
{
    /**
     * Is the process asynchronous?
     *
     * @var bool
     */
    private $async;

    /**
     * @var bool
     */
    private $debug = false;

    /**
     * Communications channels for asynchronous processes.
     *
     * @var Channel[]
     */
    private $asyncChannels = [];

    /**
     * The communication channel for a synchronous process.
     *
     * @var Channel
     */
    private $channel;

    /**
     * The function to run in the forked process.
     *
     * @var Closure
     */
    private $handler;

    /**
     * The PID of the child process.
     *
     * @var int|null
     */
    private $pid;

    /**
     * An array containing messages from child processes.
     *
     * @var array
     */
    private $messages = [];

    /**
     * The message buffer size.
     *
     * @var int
     */
    private $messageBuffer = 1024;

    /**
     * Create the fork object.
     *
     * @param bool $async
     */
    public function __construct(Closure $handler, $async = true)
    {
        if (!$this->isSupported()) {
            throw new RuntimeException('The pcntl functions cannot be found. Is the pcntl extension installed?');
        }

        $this->handler  = $handler;
        $this->async    = $async;
        $this->messages = [];

        $ref = new ReflectionFunction($handler);

        if ('bool' !== (string) $ref->getReturnType()) {
            throw new BadMethodCallException('Closures for forked processes must return a boolean to indicate success/failure');
        }
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    public function setDebug(bool $debug): self
    {
        $this->debug = $debug;

        return $this;
    }

    /**
     * Check if the system has support for the pcntl functions
     * used by this library.
     */
    public function isSupported(): bool
    {
        return function_exists('pcntl_fork');
    }

    /**
     * Set the message buffer for communication with forked processes.
     */
    public function setMessageBuffer(int $buffer): self
    {
        $this->messageBuffer = $buffer;

        return $this;
    }

    /**
     * Run the forked process.
     *
     * @param ... $args
     */
    public function run(...$args): bool
    {
        $this->channel = new Channel($this, $this->messageBuffer);

        // If the debug flag is set, we run the handler without forking so that
        // we can see and handle any errors within
        if ($this->debug) {
            $handler = $this->handler;
            $handler = $handler->bindTo($this->channel);

            return $handler(...$args);
        }

        $this->pid = pcntl_fork();

        // If $pid is set, we are in the parent process
        if ($this->pid) {
            // If we want the process to run asynchronously,
            // we can just store the PID and abandon it
            if ($this->async) {
                // Store the communication channel for the process
                $this->asyncChannels[$this->pid] = $this->channel;

                return true;
            }

            // Wait for the child process to complete before continuing
            return $this->wait();
        }

        // If $pid is not set, we are in the child process
        if (!$this->pid) {
            // Bind the channel as the $this argument within the handler
            $handler = $this->handler;
            $handler = $handler->bindTo($this->channel);

            // Call the handler
            $successful = $handler(...$args);

            // Capture the return value from the function and use
            // it to set the exit status for the process
            $status = $successful ? 0 : 1;

            // Exit the child process
            exit($status);
        }

        // We shouldn't ever get this far, so if we do
        // something went wrong
        return false;
    }

    /**
     * Wait for a child process to complete.
     *
     * @throws BadMethodCallException
     */
    public function wait(): bool
    {
        if (!$this->pid) {
            throw new BadMethodCallException('wait can only be called from the parent of a forked process');
        }

        // Cascade the call to waitAll for asynchronous processes
        if ($this->async) {
            return $this->waitAll();
        }

        // Wait for the process to complete
        pcntl_waitpid($this->pid, $status);

        // Capture any messages returned by the child process
        if ($msg = $this->channel->receive()) {
            $this->messages[] = $msg;
        }

        $this->channel->close();

        // If the process did not exit gracefully, mark it as failed
        if (!pcntl_wifexited($status)) {
            return false;
        }

        // If the process exited gracefully, check the exit code
        return 0 === pcntl_wexitstatus($status);
    }

    /**
     * Wait for all child processes to complete.
     *
     * @throws BadMethodCallException
     *
     * @return bool Whether ANY of the child processes failed
     */
    public function waitAll(): bool
    {
        if (!$this->pid) {
            throw new BadMethodCallException('waitAll can only be called from the parent of a forked process');
        }

        if (!$this->async) {
            throw new BadMethodCallException('waitAll can only be used with asynchronous forked processes');
        }

        $statuses       = [];
        $this->messages = [];

        // We loop through each of the async channels in turn.
        // Although this means the loop will check each process in
        // the order it was launched, rather than in the order they
        // complete, it allows us to keep track of the PIDs of each
        // of the child processes and receive messages
        foreach ($this->asyncChannels as $pid => $channel) {
            // Wait for a process exit signal for the PID
            pcntl_waitpid($pid, $status);

            // Capture any messages returned by the child process
            if ($msg = $channel->receive()) {
                $this->messages[] = $msg;
            }

            $channel->close();

            // If the process exited gracefully, report success/failure
            // base on the exit status
            if (pcntl_wifexited($status)) {
                $statuses[$pid] = (0 === pcntl_wexitstatus($status));
                continue;
            }

            // In all other cases, the process failed for another reason,
            // so we mark it as failed
            $statuses[$pid] = false;
        }

        // Filter the array of statuses, and check whether the count
        // of failed processes is greater than zero
        return 0 === count(array_filter($statuses, function (int $status) {
            return 0 !== $status;
        }));
    }

    /**
     * Check if the handler has any messages from child processes.
     */
    public function hasMessages(): bool
    {
        return count($this->messages) > 0;
    }

    /**
     * Get any messages received from child processes.
     */
    public function getMessages(): Generator
    {
        if (!$this->debug && !$this->pid) {
            throw new BadMethodCallException('getMessages can only be called from the parent of a forked process');
        }

        // Loop through the messages and yield each item in the collection
        foreach ($this->messages as $message) {
            yield $message;
        }
    }

    /**
     * @param mixed $msg
     */
    public function addMessage($msg): self
    {
        $this->messages[] = $msg;

        return $this;
    }

    /**
     * Clear all messages generated by child processes. This is useful if
     * messages are read within each iteration of a loop, rather than pooled
     * until the end.
     */
    public function clearMessages(): self
    {
        $this->messages = [];

        return $this;
    }
}
