<?php

namespace Superrb\Async;

use Socket as GlobalSocket;

class Socket
{
    /**
     * The socket which this process reads and writes to.
     *
     * @var GlobalSocket
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
     */
    public function __construct(GlobalSocket $socket, int $buffer = 1024)
    {
        $this->socket = $socket;
        $this->buffer = $buffer;
    }

    /**
     * Send a message to the socket.
     *
     * @param mixed $msg
     */
    public function send($msg): bool
    {
        $msg = $this->encode($msg);

        // Check the message fits within the buffer
        if (strlen($msg) > $this->buffer) {
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
     * @return mixed
     */
    public function receive()
    {
        // Read data from the socket
        socket_recv($this->socket, $msg, $this->buffer, MSG_DONTWAIT);

        return $this->decode($msg);
    }

    /**
     * Close the socket.
     */
    public function close(): void
    {
        socket_close($this->socket);
    }

    /**
     * @param mixed $msg
     */
    public function encode($msg): string
    {
        // JSON encode message if supplied in array or object form
        if (is_array($msg) || is_object($msg)) {
            $msg = json_encode($msg);
        }

        // Ensure message is a string
        return (string) $msg;
    }

    /**
     * @param string $msg
     */
    public function decode(?string $msg = null)
    {
        if (false === $msg || null === $msg) {
            return null;
        }

        // Trim the padding from the message content
        $msg = trim($msg);

        // If message is not valid JSON, return it verbatim
        if (null === json_decode($msg)) {
            return $msg;
        }

        // Decode and return the message content
        return json_decode($msg);
    }
}
