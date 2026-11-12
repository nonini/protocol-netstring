<?php

namespace nonini\Protocol\NetString;

use nonini\Protocol\Exception\ProtocolError;
use nonini\Protocol\Generic\AbstractStreamWrapper;
use RuntimeException;
use Stringable;

use function ltrim;
use function sprintf;
use function strlen;
use function strpos;
use function substr;

class StreamWrapper extends AbstractStreamWrapper
{
    protected string $buffer = '';
    protected int $bufferLength = 0;
    protected int $bufferOffset = 0;
    protected ?int $expectedLength = null;

    public function close(): void
    {
        // We might want to complain when buffer is not empty
        $this->buffer = '';
        $this->bufferLength = 0;
        $this->bufferOffset = 0;
        $this->expectedLength = null;
        parent::close();
    }

    public function handleData(string $data): void
    {
        $this->buffer .= $data;
        $this->bufferLength += strlen($data);
        while ($this->bufferHasPacket()) {
            $this->processNextPacket();

            if ($this->bufferOffset !== 0) {
                $this->buffer = substr($this->buffer, $this->bufferOffset);
                $this->bufferOffset = 0;
                $this->bufferLength = strlen($this->buffer);
            }
        }
    }

    public function write($data): bool
    {
        if (! is_string($data) && !($data instanceof Stringable)) {
            throw new RuntimeException('I expect strings, cannot write ' . get_debug_type($data));
        }
        return $this->output->write(strlen($data) . ':' . $data . ',');
    }

    public function end($data = null)
    {
        if ($data !== null) {
            $this->write($data);
        }

        $this->output->end();
    }

    protected function bufferHasPacket(): bool
    {
        if ($this->expectedLength === null) {
            if (false !== ($pos = strpos(substr($this->buffer, $this->bufferOffset, 10), ':'))) {
                $lengthString = ltrim(substr($this->buffer, $this->bufferOffset, $pos), ',');
                if (! \ctype_digit($lengthString)) {
                    $this->emit('error', [
                        new ProtocolError("Invalid length $lengthString")
                    ]);
                    $this->close();

                    return false;
                }
                $this->expectedLength = (int) $lengthString;
                $this->bufferOffset = $pos + 1;
            } elseif ($this->bufferLength > ($this->bufferOffset + 10)) {
                $this->throwInvalidBuffer();
                $this->close();

                return false;
            } else {
                return false;
            }
        }

        return $this->bufferLength > ($this->bufferOffset + $this->expectedLength);
    }

    protected function processNextPacket(): void
    {
        $packet = substr($this->buffer, $this->bufferOffset, $this->expectedLength);

        $this->bufferOffset = $this->bufferOffset + $this->expectedLength;
        $this->expectedLength = null;

        $this->emit('data', [$packet]);
    }

    protected function throwInvalidBuffer(): void
    {
        $len = strlen($this->buffer);
        if ($len < 200) {
            $debug = $this->buffer;
        } else {
            $debug = substr($this->buffer, 0, 100)
                . sprintf('[..] truncated %d bytes [..] ', $len)
                . substr($this->buffer, -100);
        }

        $this->emit('error', [
            new ProtocolError("Got invalid NetString data: $debug")
        ]);
    }
}
