<?php

namespace Mautic\EmailBundle\Swiftmailer\Momentum\Callback;

use Mautic\EmailBundle\Swiftmailer\SendGrid\Exception\ResponseItemException;
use Symfony\Component\HttpFoundation\Request;

class ResponseItems implements \Iterator
{
    /**
     * @var int
     */
    private $position = 0;

    /**
     * @var ResponseItem[]
     */
    private $items = [];

    public function __construct(Request $request)
    {
        $payload = $request->request->all();
        foreach ($payload as $item) {
            $msys = $item['msys'];
            if (isset($msys['message_event'])) {
                $event = $msys['message_event'];
            } elseif (isset($msys['unsubscribe_event'])) {
                $event = $msys['unsubscribe_event'];
            } else {
                continue;
            }

            if (isset($event['rcpt_type']) && 'to' !== $event['rcpt_type']) {
                // Ignore cc/bcc

                continue;
            }

            $bounceClass = isset($event['bounce_class']) ? (int) $event['bounce_class'] : null;
            if (empty($event['type']) || !CallbackEnum::shouldBeEventProcessed($event['type'], $bounceClass)) {
                continue;
            }

            try {
                $this->items[] = new ResponseItem($event);
            } catch (ResponseItemException $e) {
            }
        }
    }

    /**
     * Return the current element.
     *
     * @see  http://php.net/manual/en/iterator.current.php
     *
     * @return ResponseItem
     */
    public function current(): mixed
    {
        return $this->items[$this->position];
    }

    /**
     * Move forward to next element.
     *
     * @see  http://php.net/manual/en/iterator.next.php
     */
    public function next(): void
    {
        ++$this->position;
    }

    /**
     * Return the key of the current element.
     *
     * @see  http://php.net/manual/en/iterator.key.php
     *
     * @return int
     */
    public function key(): mixed
    {
        return $this->position;
    }

    /**
     * Checks if current position is valid.
     *
     * @see  http://php.net/manual/en/iterator.valid.php
     */
    public function valid(): bool
    {
        return isset($this->items[$this->position]);
    }

    /**
     * Rewind the Iterator to the first element.
     *
     * @see  http://php.net/manual/en/iterator.rewind.php
     */
    public function rewind(): void
    {
        $this->position = 0;
    }
}
