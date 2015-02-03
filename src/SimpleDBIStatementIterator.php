<?php
/**
 * SimpleDBIStatementIterator
 * Like https://github.com/doctrine/doctrine2/blob/master/lib/Doctrine/ORM/Internal/Hydration/IterableResult.php
 */
class SimpleDBIStatementIterator implements \Iterator
{
    protected $stmt,
            $current,
            $cursor;

    private $rewinded = false;

    public function __construct(\PDOStatement $stmt)
    {
        $this->stmt = $stmt;
    }

    public function rewind()
    {
        if ($this->rewinded == true) {
            throw new \SimpleDBIException("Can only iterate a Result once.");
        } else {
            $this->current = false;
            $this->cursor = -1;
            $this->next();
        }
    }

    public function valid()
    {
        return (false !== $this->current);
    }

    public function current()
    {
        return $this->current;
    }

    public function key()
    {
        return $this->cursor;
    }

    public function next()
    {
        $row = $this->stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            $this->cursor++;
            $this->current = $row;
        } else {
            $this->current = false;
        }
    }
}
