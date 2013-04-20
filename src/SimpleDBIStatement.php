<?php
/**
 * プリペアドステートメントを扱う PDOStatement クラスを拡張したサブクラス
 *
 */
class SimpleDBIStatement extends PDOStatement
{
    protected $pdo;
    public $exec_time;

    protected function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function execute($params = array())
    {
        $ts = microtime(true);
        $r = parent::execute($params);
        $this->exec_time = microtime(true) - $ts;
        return $r;
    }
}
