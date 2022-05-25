<?php

namespace Superrb\Async;

class Channel
{
    /**
     * The handler which owns this channel.
     *
     * @var Handler
     */
    private $handler;

    /**
     * The socket domain to use.
     *
     * @var int
     */
    private $domain;

    /**
     * The parent socket.
     *
     * @var Socket
     */
    private $parent;

    /**
     * The child socket.
     *
     * @var Socket
     */
    private $child;

    /**
     * Creates a socket pairing which can be used to communicate between
     * asynchronous running processes.
     *
     * @param int     $buffer
     * @param Handler $handler
     */
    public function __construct(Handler $handler, int $buffer = 1024)
    {
        $this->handler = $handler;

        // On Windows we need to use AF_INET
        $this->domain = ('WIN' == strtoupper(substr(PHP_OS, 0, 3)) ? AF_INET : AF_UNIX);

        if (false === socket_create_pair($this->domain, SOCK_STREAM, 0, $sockets)) {
            throw new SocketCreationException('Socket pair failed to create: '.socket_strerror(socket_last_error()));
        }

        [$parent, $child] = $sockets;

        $this->parent = new Socket($parent, $buffer);
        $this->child  = new Socket($child, $buffer);
    }

    /**
     * Close both socket pairs on destruct.
     */
    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        $this->parent->close();
        $this->parent = null;
        $this->child->close();
        $this->child = null;
    }

    /**
     * Get the parent socket from the pairing.
     *
     * @return Socket
     */
    public function getParentSocket(): Socket
    {
        return $this->parent;
    }

    /**
     * Get the child socket from the pairing.
     *
     * @return Socket
     */
    public function getChildSocket(): Socket
    {
        return $this->child;
    }

    /**
     * Send a message to the child socket.
     *
     * @param mixed $msg
     *
     * @return bool
     */
    public function send($msg): bool
    {
        // In debug mode, bypass the socket and just store messages directly
        // against the handler, mimicking the encode/decode process
        if ($this->handler->isDebug()) {
            $this->handler->addMessage($this->child->decode($this->child->encode($msg)));

            return true;
        }

        // Channel communication within processes is one way,
        // so we cascade the call straight to the child socket
        return $this->child->send($msg);
    }

    /**
     * Receive a message from the parent socket.
     *
     * @return mixed
     */
    public function receive()
    {
        // Channel communication within processes is one way,
        // so we cascade the call straight to the parent socket
        return $this->parent->receive();
    }
}
