<?php
class SimpleDBI_adapter_Test extends PHPUnit_Framework_TestCase
{
    protected function setUp()
    {
        $db = SimpleDBI::conn();
        $db->query('CREATE TABLE IF NOT EXISTS test_from (id INT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(255), PRIMARY KEY (id)) ENGINE=InnoDB;');
        $db->query('CREATE TABLE IF NOT EXISTS test_to    (id INT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(255), PRIMARY KEY (id)) ENGINE=InnoDB;');
        $db->query('TRUNCATE TABLE test_from');
        $db->query('TRUNCATE TABLE test_to');
    }

    protected function tearDown()
    {
        $db = SimpleDBI::conn();
        $db->query('DROP TABLE test_from');
        $db->query('DROP TABLE test_to');
    }

    public function test_decorator()
    {
        $db = SimpleDBI::conn();
        $this->assertEquals(0, $db->value('SELECT COUNT(1) FROM test_from'));
        $this->assertEquals(0, $db->value('SELECT COUNT(1) FROM test_to'));

        $db->query('INSERT INTO test_from (name) values ("name")');
        $this->assertEquals(1, $db->value('SELECT COUNT(1) FROM test_from'));
        $this->assertEquals(0, $db->value('SELECT COUNT(1) FROM test_to'));

        SimpleDBI::addProxy(new SimpleDBI_Proxy_X(function($next_proxy, $dbh, $sql, $params){
                    echo "XXX\n";
                    $r = $next_proxy($dbh, $sql, $params);
                    echo "YYY\n";
                    return $r;
                }));
//        SimpleDBI::addProxy(new SimpleDBI_Proxy_Duplicator('test_from', 'test_to'));

        $db->query('INSERT INTO test_from (name) values ("name")');
        $this->assertEquals(2, $db->value('SELECT COUNT(1) FROM test_from'));
//        $this->assertEquals(1, $db->value('SELECT COUNT(1) FROM test_to'));

    }
}

