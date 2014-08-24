<?php
class SimpleDBI_proxy_Test extends PHPUnit_Framework_TestCase
{
    protected function setUp()
    {
        SimpleDBI::clearProxy();

        $db = SimpleDBI::conn();
        $db->query('CREATE TABLE IF NOT EXISTS users    (id INT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(255), PRIMARY KEY (id)) ENGINE=InnoDB;');
        $db->query('CREATE TABLE IF NOT EXISTS users_to (id INT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(255), email VARCHAR(255), PRIMARY KEY (id)) ENGINE=InnoDB;');
    }

    protected function tearDown()
    {
        SimpleDBI::clearProxy();

        $db = SimpleDBI::conn();
        $db->query('DROP TABLE users');
        $db->query('DROP TABLE users_to');
    }

    public function test_proxy_clear_cache()
    {
        $cache = array();
        SimpleDBI::addProxy(new SimpleDBI_Proxy_With_Handler(function($next_proxy, $dbh, $sql, $params) use (&$cache){
                    if(preg_match('/^\s*(DELETE\s+FROM|UPDATE)\s+users\s+/', $sql)){
                        unset($cache["user:{$params[0]}"]);
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

    public function test_proxy_double_write()
    {
        SimpleDBI::addProxy(new SimpleDBI_Proxy_With_Handler(function($next_proxy, $dbh, $sql, $params) {
                    $r = $next_proxy($dbh, $sql, $params);
                    if(preg_match('/^\s*(DELETE\s+FROM|UPDATE)\s+users\s+/', $sql)){
                        $new_sql = preg_replace('/^\s*(DELETE\s+FROM|UPDATE)\s+users\s+/', "$1 users_to ", $sql);
                        $dbh->execute_without_proxy($new_sql, $params);
                    }
                    return $r;
                }));

        $db = SimpleDBI::conn();
        $db->query('INSERT INTO users (name) values ("foo")');
        $foo_id = $db->lastInsertId();
        $db->query('INSERT INTO users_to (id, name) values (?, "foo")', [ $foo_id ]);

        # update 'users' and select from 'users_to'
        $db->query('UPDATE users SET name = ? WHERE id = ?', [ 'foo_new', $foo_id]);
        $foo_user_to = $db->row('SELECT * FROM users_to WHERE id = ?', [ $foo_id ]);
        $this->assertEquals('foo_new', $foo_user_to['name']);

        # delete 'users' and select from 'users_to'
        $db->query('DELETE FROM users WHERE id = ?', [ $foo_id]);
        $foo_user_to = $db->row('SELECT * FROM users_to WHERE id = ?', [ $foo_id ]);
        $this->assertFalse($foo_user_to);
    }
}

