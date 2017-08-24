<?php
namespace Superrb\Async;

use RuntimeException;
use BadMethodCallException;
use Closure;
use Doctrine\Common\Collections\ArrayCollection;
use Generator;
use ReflectionFunction;

class Handler
{
    /**
     * Is the process asynchronous?
     *
     * @var bool
     */
    private $async;

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
     * An ArrayCollection containing messages from child processes.
     *
     * @var ArrayCollection
     */
    private $messages;

    /**
     * The message buffer size.
     *
     * @var int
     */
    private $messageBuffer = 1024;

    /**
     * Create the fork object.
     *
     * @param bool    $async
     * @param Closure $handler
     */
    public function __construct(Closure $handler, $async = true)
    {
        if (!$this->isSupported()) {
            throw new RuntimeException('The pcntl functions cannot be found. Is the pcntl extension installed?');
        }

        $this->handler = $handler;
        $this->async = $async;
        $this->messages = new ArrayCollection();

        $ref = new ReflectionFunction($handler);

        if ((string)$ref->getReturnType() !== 'bool') {
            throw new BadMethodCallException('Closures for forked processes must return a boolean to indicate success/failure');
        }
    }

    /**
     * Check if the system has support for the pcntl functions
     * used by this library
     *
     * @return bool
     */
    public function isSupported() : bool
    {
        return function_exists('pcntl_fork');
    }

    /**
     * Set the message buffer for communication with forked processes.
     *
     * @param int $buffer
     *
     * @return self
     */
    public function setMessageBuffer(int $buffer) : self
    {
        $this->messageBuffer = $buffer;

        return $this;
    }

    /**
     * Run the forked process.
     *
     * @param ... $args
     *
     * @return bool
     */
    public function run(...$args) : bool
    {
        $this->channel = new Channel($this->messageBuffer);

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
     *
     * @return bool
     */
    public function wait() : bool
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
            $this->messages->add($msg);
        }

        // If the process did not exit gracefully, mark it as failed
        if (!pcntl_wifexited($status)) {
            return false;
        }

        // If the process exited gracefully, check the exit code
        return pcntl_wexitstatus($status) === 0;
    }

    /**
     * Wait for all child processes to complete.
     *
     * @throws BadMethodCallException
     *
     * @return bool Whether ANY of the child processes failed
     */
    public function waitAll() : bool
    {
        if (!$this->pid) {
            throw new BadMethodCallException('waitAll can only be called from the parent of a forked process');
        }

        if (!$this->async) {
            throw new BadMethodCallException('waitAll can only be used with asynchronous forked processes');
        }

        $statuses = [];
        $this->messages = new ArrayCollection();

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
                $this->messages->add($msg);
            }

            // If the process exited gracefully, report success/failure
            // base on the exit status
            if (pcntl_wifexited($status)) {
                $statuses[] = (pcntl_wexitstatus($status) === 0);
                continue;
            }

            // In all other cases, the process failed for another reason,
            // so we mark it as failed
            $statuses[] = false;
        }

        // Filter the array of statuses, and check whether the count
        // of failed processes is greater than zero
        return count(array_filter($statuses, function (int $status) {
            return $status !== 0;
        })) === 0;
    }

    /**
     * Get any messages received from child processes.
     *
     * @return Generator
     */
    public function getMessages() : Generator
    {
        if (!$this->pid) {
            throw new BadMethodCallException('getMessages can only be called from the parent of a forked process');
        }

        // Loop through the messages and yield each item in the collection
        foreach ($this->messages as $message) {
            yield $message;
        }
    }

    /**
     * Clear all messages generated by child processes. This is useful if
     * messages are read within each iteration of a loop, rather than pooled
     * until the end.
     *
     * @return self
     */
    public function clearMessages() : self
    {
        $this->messages = new ArrayCollection();

        return $this;
    }
}
