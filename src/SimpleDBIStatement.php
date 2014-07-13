<?php
/**
 * プリペアドステートメントを扱う PDOStatement クラスを拡張したサブクラス
 *
 */
class SimpleDBIStatement extends PDOStatement
{
    public $exec_time;

    public function execute($params = array())
    {
        $ts = microtime(true);
        $r = parent::execute($params);
        $this->exec_time = microtime(true) - $ts;

        return $r;
    }
}
