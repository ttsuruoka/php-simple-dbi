<?php
require_once dirname(__DIR__).'/SimpleDBI.php';

class SimpleDBITest extends PHPUnit_Framework_TestCase
{
    public function test_parseSQL()
    {
        list($sql, $params) = SimpleDBI::parseSQL('SELECT * FROM test');
        $this->assertEquals('SELECT * FROM test', $sql);
        $this->assertEquals(array(), $params);

        // 通常のクエリーの展開: 位置パラメータひとつ
        list($sql, $params) = SimpleDBI::parseSQL('SELECT * FROM test WHERE id = ?', array(10));
        $this->assertEquals('SELECT * FROM test WHERE id = ?', $sql);
        $this->assertEquals(array(10), $params);

        // 通常のクエリーの展開: 名前付きパラメータひとつ
        list($sql, $params) = SimpleDBI::parseSQL('SELECT * FROM test WHERE id = :id', array('id' => 10));
        $this->assertEquals('SELECT * FROM test WHERE id = :id', $sql);
        $this->assertEquals(array('id' => 10), $params);

        // IN 句の展開: ? がひとつだけのとき
        list($sql, $params) = SimpleDBI::parseSQL('SELECT * FROM test WHERE id IN (?)', array(array(10, 20, 30)));
        $this->assertEquals('SELECT * FROM test WHERE id IN (?, ?, ?)', $sql);
        $this->assertEquals(array(10, 20, 30), $params);

        // IN 句の展開: ? がひとつだけのとき
        list($sql, $params) = SimpleDBI::parseSQL('SELECT * FROM test WHERE id IN (?)', array(array(10)));
        $this->assertEquals('SELECT * FROM test WHERE id IN (?)', $sql);
        $this->assertEquals(array(10), $params);

        // IN 句の展開: ? が複数のとき
        list($sql, $params) = SimpleDBI::parseSQL('SELECT * FROM test WHERE id IN (?) AND age > ?', array(array(10, 20, 30), 50));
        $this->assertEquals('SELECT * FROM test WHERE id IN (?, ?, ?) AND age > ?', $sql);
        $this->assertEquals(array(10, 20, 30, 50), $params);

        // IN 句の展開: ? に空の配列を割り当てようとしているとき
        list($sql, $params) = SimpleDBI::parseSQL('SELECT * FROM test WHERE id IN (?)', array(array()));
        $this->assertEquals('SELECT * FROM test WHERE id IN (?)', $sql);
        $this->assertEquals(array(null), $params);

        // IN 句の展開: IN 句が複数のとき
        list($sql, $params) = SimpleDBI::parseSQL('SELECT * FROM test WHERE id IN (?) AND age IN (?)', array(array(10, 20, 30), array(50, 60)));
        $this->assertEquals('SELECT * FROM test WHERE id IN (?, ?, ?) AND age IN (?, ?)', $sql);
        $this->assertEquals(array(10, 20, 30, 50, 60), $params);

        // IN 句の展開: named パラメータのとき
        list($sql, $params) = SimpleDBI::parseSQL('SELECT * FROM test WHERE id IN (:foo)', array(':foo' => array(10, 20, 30)));
        $this->assertEquals('SELECT * FROM test WHERE id IN (:foo_0, :foo_1, :foo_2)', $sql);
        $this->assertEquals(array(':foo_0' => 10, ':foo_1' => 20, ':foo_2' => 30), $params);

        // IN 句の展開: named パラメータのとき
        list($sql, $params) = SimpleDBI::parseSQL('SELECT * FROM test WHERE id IN (:foo)', array(':foo' => array(10)));
        $this->assertEquals('SELECT * FROM test WHERE id IN (:foo_0)', $sql);
        $this->assertEquals(array(':foo_0' => 10), $params);

        // IN 句の展開: named パラメータのとき + パラメータが複数
        list($sql, $params) = SimpleDBI::parseSQL('SELECT * FROM test WHERE id IN (:foo) AND age > :age', array(':foo' => array(10, 20, 30), ':age' => 50));
        $this->assertEquals('SELECT * FROM test WHERE id IN (:foo_0, :foo_1, :foo_2) AND age > :age', $sql);
        $this->assertEquals(array(':foo_0' => 10, ':foo_1' => 20, ':foo_2' => 30, ':age' => 50), $params);

        // IN 句の展開: named パラメータのとき + IN 句が複数
        list($sql, $params) = SimpleDBI::parseSQL('SELECT * FROM test WHERE id IN (:foo) AND age IN (:age)', array(':foo' => array(10, 20, 30), ':age' => array(50, 60)));
        $this->assertEquals('SELECT * FROM test WHERE id IN (:foo_0, :foo_1, :foo_2) AND age IN (:age_0, :age_1)', $sql);
        $this->assertEquals(array(':foo_0' => 10, ':foo_1' => 20, ':foo_2' => 30, ':age_0' => 50, ':age_1' => 60), $params);

        // IN 句の展開: named パラメータのとき + 同名の IN 句が複数
        list($sql, $params) = SimpleDBI::parseSQL('SELECT * FROM test WHERE id IN (:foo) UNION SELECT * FROM test2 WHERE id IN (:foo)', array(':foo' => array(10, 20, 30)));
        $this->assertEquals('SELECT * FROM test WHERE id IN (:foo_0, :foo_1, :foo_2) UNION SELECT * FROM test2 WHERE id IN (:foo_0, :foo_1, :foo_2)', $sql);
        $this->assertEquals(array(':foo_0' => 10, ':foo_1' => 20, ':foo_2' => 30), $params);

        // IN 句の展開: named パラメータのとき + パラメータでコロンがついていないとき
        list($sql, $params) = SimpleDBI::parseSQL('SELECT * FROM test WHERE id IN (:foo)', array('foo' => array(10, 20, 30)));
        $this->assertEquals('SELECT * FROM test WHERE id IN (:foo_0, :foo_1, :foo_2)', $sql);
        $this->assertEquals(array(':foo_0' => 10, ':foo_1' => 20, ':foo_2' => 30), $params);

        // IN 句の展開: named パラメータのとき + 数値添字配列
        list($sql, $params) = SimpleDBI::parseSQL('SELECT * FROM test WHERE id IN (:foo)', array('foo' => array(1 => 10, 4 => 40, 2 => 20)));
        $this->assertEquals('SELECT * FROM test WHERE id IN (:foo_1, :foo_4, :foo_2)', $sql);
        $this->assertEquals(array(':foo_1' => 10, ':foo_2' => 20, ':foo_4' => 40), $params);

        // IN 句の展開: named パラメータのとき + 文字添字配列
        list($sql, $params) = SimpleDBI::parseSQL('SELECT * FROM test WHERE id IN (:foo)', array('foo' => array('bar' => 10, 'baz' => 20, 'qux' => 40)));
        $this->assertEquals('SELECT * FROM test WHERE id IN (:foo_bar, :foo_baz, :foo_qux)', $sql);
        $this->assertEquals(array(':foo_bar' => 10, ':foo_baz' => 20, ':foo_qux' => 40), $params);
    }
}
