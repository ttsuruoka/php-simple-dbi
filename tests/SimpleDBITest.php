<?php
class SimpleDBITest extends PHPUnit_Framework_TestCase
{
    public static function setUpBeforeClass()
    {
        $load_constant = function($name) {
            if (!defined($name)) {
                if (!isset($GLOBALS[$name])) {
                    throw new Exception("{$name} is not defined");
                }
                define($name, $GLOBALS[$name]);
            }
        };

        $load_constant('DB_DSN');
        $load_constant('DB_USERNAME');
        $load_constant('DB_PASSWORD');
        $load_constant('DB_SLAVE_DSN');
        $load_constant('DB_SLAVE_USERNAME');
        $load_constant('DB_SLAVE_PASSWORD');
    }

    public function test_getConnectSettings()
    {
        list($dsn, $username, $password, $driver_options) = SimpleDBI::getConnectSettings();
        $this->assertEquals(DB_DSN, $dsn);
        $this->assertEquals(DB_USERNAME, $username);
        $this->assertEquals(DB_PASSWORD, $password);
        $this->assertEquals(array(), $driver_options);
    }

    public function test_conn()
    {
        $db = SimpleDBI::conn();
        $this->assertInstanceOf('SimpleDBI', $db);
        $this->assertEquals(null, $db->getDestination());
        $this->assertEquals(DB_DSN, $db->getDSN());
        $this->assertEquals(DB_USERNAME, $db->getUserName());
        $this->assertEquals(DB_PASSWORD, $db->getPassword());
        $this->assertEquals(array(), $db->getDriverOptions());
    }

    /**
     * disconnect によって DB 接続を切断できることをテストする
     */
    public function test_disconnect()
    {
        $db = SimpleDBI::conn();

        $value = $db->value('SELECT 1');
        $this->assertEquals(1, $value);

        $db->disconnect();

        try {
            $db->value('SELECT 1');
            $this->fail();
        } catch (SimpleDBIException $e) {
            $this->assertEquals('Database not connected', $e->getMessage());
        }

        try {
            $db->begin();
            $this->fail();
        } catch (SimpleDBIException $e) {
            $this->assertEquals('Database not connected', $e->getMessage());
        }

        try {
            $db->lastInsertId();
            $this->fail();
        } catch (SimpleDBIException $e) {
            $this->assertEquals('Database not connected', $e->getMessage());
        }
    }

    /**
     * トランザクション中は disconnect できないことをテストする
     */
    public function test_disconnect_02()
    {
        $db = SimpleDBI::conn();

        $db->begin();

        try {
            $db->disconnect();
            $this->fail();
        } catch (SimpleDBIException $e) {
            $this->assertEquals('Cannot disconnect while a transaction is in progress', $e->getMessage());
        }

        try {
            $db->rollback();
            $db->disconnect();
        } catch (SimpleDBIException $e) {
            $this->fail();
        }
    }

    /**
     * disconnect によって接続が切断されることをテストする
     */
    public function test_disconnect_03()
    {
        $db = SimpleDBI::conn();

        $row = $db->row('SHOW STATUS LIKE "Threads_connected"');
        $connected_a = $row['Value'];

        $this->assertGreaterThan(0, $connected_a);

        $db2 = SimpleDBI::conn('slave');

        $row = $db->row('SHOW STATUS LIKE "Threads_connected"');
        $connected_b = $row['Value'];

        $this->assertEquals($connected_a + 1, $connected_b);

        $db2->disconnect();

        $retry = 3;
        $disconnected = false;
        for ($i = 0; $i <= $retry; $i++) {
            $row = $db->row('SHOW STATUS LIKE "Threads_connected"');
            $connected_c = $row['Value'];
            if ($connected_a == $connected_c) {
                $disconnected = true;
                break;
            }
            // すぐに接続数に反映されないことがあるので少し待つ
            sleep(1);
        }

        $this->assertTrue($disconnected);
    }

    /**
     * conn/disconnect を繰り返せることをテストする
     */
    public function test_disconnect_04()
    {
        $db = SimpleDBI::conn();
        $value = $db->value('SELECT 1');
        $this->assertEquals(1, $value);
        $db->disconnect();

        $db = SimpleDBI::conn();
        $value = $db->value('SELECT 1');
        $this->assertEquals(1, $value);
        $db->disconnect();
    }

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
