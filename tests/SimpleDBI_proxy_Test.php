<?php
class SimpleDBI_proxy_Test extends PHPUnit_Framework_TestCase
{
    protected function setUp()
    {
        $db = SimpleDBI::conn();
        $db->query('CREATE TABLE IF NOT EXISTS users (id INT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(255), PRIMARY KEY (id)) ENGINE=InnoDB;');
        $db->query('TRUNCATE TABLE users');
    }

    protected function tearDown()
    {
        $db = SimpleDBI::conn();
        $db->query('DROP TABLE users');
    }

    public function test_proxy_clear_cache()
    {
        $cache = [];
        SimpleDBI::addProxy(new SimpleDBI_Proxy_With_Handler(function($next_proxy, $dbh, $sql, $params) use (&$cache){
                    if(preg_match('/^\s*(DELETE\s+FROM|UPDATE)\s+users\s+/', $sql)){
                        $key = "user:{$params[0]}";
                        unset($cache[$key]);
                    }
                    return $next_proxy($dbh, $sql, $params);
                }));

        $db = SimpleDBI::conn();
        $db->query('INSERT INTO users (name) values ("foo")');
        $foo_id = $db->lastInsertId();
        $foo_key = "user:{$foo_id}";
        $db->query('INSERT INTO users (name) values ("bar")');
        $bar_id = $db->lastInsertId();
        $bar_key = "user:{$bar_id}";

        $foo_user = $db->row('SELECT * FROM users WHERE id = ?', [ $foo_id ]);
        $bar_user = $db->row('SELECT * FROM users WHERE id = ?', [ $bar_id ]);
        $cache[$foo_key] = $foo_user;
        $cache[$bar_key] = $bar_user;
        $this->assertArrayHasKey($foo_key, $cache);
        $this->assertArrayHasKey($bar_key, $cache);

        $db->query('DELETE FROM users WHERE id = ?', [ $foo_id ]);
        $this->assertFalse(array_key_exists($foo_key, $cache));
        $this->assertArrayHasKey($bar_key, $cache);

        $cache[$foo_key] = $foo_user;
        $db->query('UPDATE users SET name = "B A R" WHERE id = ?', [ $bar_id ]);
        $this->assertArrayHasKey($foo_key, $cache);
        $this->assertFalse(array_key_exists($bar_key, $cache));
    }
}

