<?php
/**
 * SimpleDBIStatementIterator
 * Like https://github.com/doctrine/doctrine2/blob/master/lib/Doctrine/ORM/Internal/Hydration/IterableResult.php
 */
class SimpleDBIStatementIterator implements Iterator
{
    protected $stmt,
            $current = false,
            $cursor = null;

    private $rewinded = false;

    public function __construct(PDOStatement $stmt)
    {
        $this->stmt = $stmt;
    }

    public function rewind(): void
    {
        if ($this->rewinded == true) {
            throw new SimpleDBIException("Can only iterate a Result once.");
        } else {
            $this->rewinded = true;
            $this->current = false;
            $this->cursor = null;
            $this->next();
        }
    }

    public function valid(): bool
    {
        return (false !== $this->current);
    }

    public function current(): mixed
    {
        return $this->current;
    }

    public function key(): mixed
    {
        return $this->cursor;
    }

    public function next(): void
    {
        $row = $this->stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            if (null === $this->cursor) {
                $this->cursor = 0;
            } else {
                $this->cursor++;
            }
            $this->current = $row;
        } else {
            $this->cursor = null;
            $this->current = false;
        }
    }
}
