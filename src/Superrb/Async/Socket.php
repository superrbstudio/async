<?php

namespace Superrb\Async;

use BadMethodCallException;
use resource;

class Socket
{
    /**
     * The socket which this process reads and writes to.
     *
     * @var resource
     */
    private $socket;

    /**
     * The size of the buffer when sending and receiving messages.
     *
     * @var int
     */
    private $buffer;

    /**
     * Create the socket handler.
     *
     * @param resource $socket
     * @param int      $buffer
     */
    public function __construct($socket, int $buffer = 1024)
    {
        if (!is_resource($socket)) {
            throw new BadMethodCallException('$socket must be a resource');
        }

        $this->socket = $socket;
        $this->buffer = $buffer;
    }

    /**
     * Send a message to the socket.
     *
     * @param string|array|object $msg
     *
     * @return bool
     */
    public function send($msg): bool
    {
        // JSON encode message if supplied in array or object form
        if (is_array($msg) || is_object($msg)) {
            $msg = json_encode($msg);
        }

        // Ensure message is a string
        $msg = (string) $msg;

        // Check the message fits within the buffer
        if (strlen($msg) > $this->buffer) {
            var_dump($msg);
            throw new SocketCommunicationException('Tried to send data larger than buffer size of '.$this->buffer.' bytes. Recreate channel with a larger buffer to send this data');
        }

        // Pad the message to the buffer size
        $msg = str_pad($msg, $this->buffer, ' ');

        // Write the message to the socket
        return socket_write($this->socket, $msg, $this->buffer);
    }

    /**
     * Receive data from the socket.
     *
     * @return string|array|object|null
     */
    public function receive()
    {
        // Read data from the socket
        socket_recv($this->socket, $msg, $this->buffer, MSG_DONTWAIT);

        if ($msg === false) {
            return null;
        }

        // Trim the padding from the message content
        $msg = trim($msg);

        // If message is not valid JSON, return it verbatim
        if (json_decode($msg) === null) {
            return $msg;
        }

        // Decode and return the message content
        return json_decode($msg);
    }

    /**
     * Close the socket.
     */
    public function close(): void
    {
        socket_close($this->socket);
    }
}
