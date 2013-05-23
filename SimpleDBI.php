<?php
/**
 * Simple database interface class for PHP
 *
 * @license MIT License
 * @author Tatsuya Tsuruoka <http://github.com/ttsuruoka>
 */

require_once __DIR__ . '/SimpleDBIStatement.php';

class SimpleDBI
{
    protected $pdo = null;      // PDO インスタンス
    protected $dsn = null;      // DSN
    protected $st = null;       // SimpleDBIStatement ステートメント
    protected $trans_stack = array();   // トランザクションのネストを管理する
    protected $is_uncommitable = false; // commit可能な状態かどうか

    protected function __construct($dsn, $username, $password, $driver_options)
    {
        $this->pdo = new PDO($dsn, $username, $password, $driver_options);

        // エラーモードを例外に設定
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // PDOStatement ではなく SimpleDBIStatement を使うように設定
        $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, array('SimpleDBIStatement', array($this->pdo)));

        $this->dsn = $dsn;
    }

    /**
     * データベースの接続設定を取得する
     *
     * このメソッドは、SimpleDBI クラスのサブクラスでオーバーライドして使われます。
     *
     * @param string $destination 接続先
     * @return array DSN などの接続設定の配列
     */
    public static function getConnectSettings($destination = null)
    {
        $dsn = DB_DSN;
        $username = DB_USERNAME;
        $password = DB_PASSWORD;
        $driver_options = array();

        return array($dsn, $username, $password, $driver_options);
    }

    /**
     * データベース接続のインスタンスを取得する
     *
     * $destination は、接続先をあらわす文字列で、
     * 場合によっては データベースのホスト名と一致しないこともあります。
     * 
     * $destination から実際に接続するデータベースの設定を取得するために、
     * getConnectSettings() メソッドを呼び出します。
     *
     * @param string $destination 接続先
     * @return SimpleDBI
     */
    public static function conn($destination = null)
    {
        static $instances = array();

        list($dsn, $username, $password, $driver_options) = static::getConnectSettings($destination);

        if (isset($instances[$dsn])) {
            return $instances[$dsn];
        }

        $instances[$dsn] = new static($dsn, $username, $password, $driver_options);
        $instances[$dsn]->onConnect();
        return $instances[$dsn];
    }

    /**
     * データベースインスタンスに接続完了時に呼ばれるメソッド
     *
     * @return void
     */
    protected function onConnect()
    {
    }

    /**
     * クエリーの実行が完了したときの呼ばれるメソッド
     *
     * このメソッドはオーバーライドして使います。
     *
     * 例）実行時間をデバッグ出力
     *
     *   Log::debug($this->st->exec_time);
     *
     * @param string $sql 実行した SQL
     * @param array $params SQL にバインドされたパラメータ
     * @return void
     */
    protected function onQueryEnd($sql, array $params = array())
    {
    }

    protected function setStatementClass($statement_class)
    {
        $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, array($statement_class, array($this)));
    }

    /**
     * 実行する SQL をパースする
     *
     * PDO と PDOStatement では対応できない機能に対応するために、
     * SQL をパースします。
     *
     * このメソッドで、IN 句の展開に対応しています。
     *
     * @param string $sql
     * @param array $params
     * @return array パースされた SQL とパラメータ
     */
    public static function parseSQL($sql, array $params = array())
    {
        //
        // IN 句の展開
        //

        // IN 句の展開をする必要があるかどうかを判断する
        // パラメータにひとつでも配列が含まれている = IN 句の展開が必要
        $has_array_value = false;
        $is_named_param = false; // 名前付きパラメータかどうか（:foo 形式）
        foreach ($params as $k => $v) {
            if (is_array($v)) {
                $has_array_value = true;
                $is_named_param = !is_numeric($k);
                break;
            }
        }

        // IN 句の展開をする必要があるときだけ、展開処理を行う
        //
        // IN (?) に対して array(10, 20, 30) が渡されたとき
        // IN (?, ?, ?) に展開する
        //
        // IN (:foo) に対して array(':foo' => array(10, 20, 30)) が渡されたとき
        // IN (:foo_0, :foo_1, :foo_2) に展開する
        //
        if ($has_array_value) {
            if ($is_named_param) {
                //
                // 名前付きパラメータのとき
                //
                // SQL:
                // :foo を :foo_0, :foo_1, ... に展開する
                //
                // パラメータ:
                // array(':foo' => array(10, 20, 30)) を
                // array(':foo_0' => 10, ':foo_1' => 20, ...) に展開する
                //

                $unset_keys = array();

                $sql = preg_replace_callback(
                    '/:([A-Za-z_-]+)/',
                    function($matches) use (&$params, &$unset_keys) {

                        $name = $matches[0]; // :name 形式の文字列

                        // パラメータのキーを取得
                        // 「:」がついているときとついていないとき両方に対応
                        $key = isset($params[$matches[1]]) ? $matches[1] : $matches[0];

                        $name_i_list = array();
                        if (!is_array($params[$key])) {
                            // パラメータが配列ではないときは、展開しない
                            return $name;
                        }
                        $n = count($params[$key]);
                        foreach ($params[$key] as $i => $v) {
                            $name_i = "{$name}_{$i}";
                            $name_i_list[] = $name_i;
                            $params[$name_i] = $params[$key][$i];
                        }
                        $unset_keys[] = $key;
                        return join(', ', $name_i_list);
                    },
                    $sql
                );

                // 展開済みのキーをパラメータから削除
                foreach ($unset_keys as $key) {
                    unset($params[$key]);
                }

            } else {
                // 位置パラメータのとき
                $a = explode('?', $sql);
                $sql = array_shift($a);
                $t = array();
                foreach ($params as $k => $v) {
                    if (is_array($v)) {
                        if ($v === array()) {
                            $sql .= '?';
                            $t[] = null;
                        } else {
                            $sql .= join(', ', array_fill(0, count($v), '?'));
                            $t = array_merge($t, $v);
                        }
                    } else {
                        $sql .= '?';
                        $t[] = $v;
                    }
                    $sql .= array_shift($a);
                }
                $params = $t;
            }
        }

        return array($sql, $params);
    }

    public function getLastExecTime()
    {
        return isset($this->st->exec_time) ? $this->st->exec_time : null;
    }

    /**
     * SQL を実行する
     *
     * @param string $sql
     * @param array $params
     * @throws PDOException
     */
    public function query($sql, array $params = array())
    {
        list($sql, $params) = self::parseSQL($sql, $params);
        $this->st = $this->pdo->prepare($sql);
        $r = $this->st->execute($params);
        if (!$r) {
            throw new PDOException("query failed: {$sql}");
        }
        $this->onQueryEnd($this->st->queryString, $params);
    }

    /**
     * SQL を実行して、結果から最初の1行を取得する
     *
     * @param string $sql
     * @param array $params
     * @return array|boolean 結果セットから最初の1行を配列で返す。結果が見つからなかったとき false を返す
     */
    public function row($sql, array $params = array())
    {
        $this->query($sql, $params);
        return $this->st->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * SQL を実行して、結果からすべての行を取得する
     *
     * @param string $sql
     * @param array $params
     * @return array 結果セットからすべての行を配列で返す。結果が見つからなかったとき空配列を返す
     */
    public function rows($sql, array $params = array())
    {
        $this->query($sql, $params);
        $rows = $this->st->fetchAll(PDO::FETCH_ASSOC);
        return $rows ? $rows : array();
    }

    /**
     * SQL を実行して、結果から最初の1行の最初の値を取得する
     *
     * @param string $sql
     * @param array $params
     * @return mixed 結果セットの最初の1行の最初の値を返す。結果が見つからなかったとき false を返す
     */
    public function value($sql, array $params = array())
    {
        $row = $this->row($sql, $params);
        return $row ? current($row) : false;
    }

    /**
     * 単純な INSERT 文を実行する
     *
     * @param string $table
     * @param array $params
     */
    public function insert($table, array $params)
    {
        $cols = implode(', ', array_keys($params));
        $placeholders = implode(', ', str_split(str_repeat('?', count($params))));
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, $cols, $placeholders);
        $this->query($sql, array_values($params));
    }

    /**
     * 単純な REPLACE 文を実行する
     *
     * @param string $table
     * @param array $params
     */
    public function replace($table, array $params)
    {
        $cols = implode(', ', array_keys($params));
        $placeholders = implode(', ', str_split(str_repeat('?', count($params))));
        $sql = sprintf('REPLACE INTO %s (%s) VALUES (%s)', $table, $cols, $placeholders);
        $this->query($sql, array_values($params));
    }

    /**
     * 単純な UPDATE 文を実行する
     *
     * @param string $table
     * @param array $params
     * @param array $where_params
     */
    public function update($table, array $params, array $where_params)
    {
        // 対象のカラム名と値
        $pairs = '';
        foreach ($params as $k => $v) {
            $pairs .= $k . ' = ?, ';
        }
        $pairs = substr($pairs, 0, -2);

        // WHERE 句
        $where = '';
        foreach ($where_params as $k => $v) {
            $where .= $k . ' = ? AND ';
        }
        $where = substr($where, 0, -5);

        $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, $pairs, $where);
        $this->query($sql, array_merge(array_values($params), array_values($where_params)));
    }

    /**
     * 単純な検索クエリーを実行する @DEPRECATED
     *
     * 使用例：
     * $this->search('item', 'id BETWEEN ? AND ?', array(1000, 1999), 'id DESC', array(1, 10));
     *
     * @param string $table 対象のテーブル名
     * @param string $where WHERE 句
     * @param array $params 束縛するパラメータ
     * @param string $order ORDER 句（オプション）
     * @param string $limit LIMIT 句（オプション）
     * @param array $options その他のオプション
     *                       select_expr キー：SELECT で取り出すカラム。デフォルトは * （全カラム）
     * @return array 取得結果を配列で返す。結果が見つからなかったとき空配列を返す
     */
    public function search($table, $where, $params, $order = null, $limit = null, $options = array())
    {
        // SELECT で取り出すカラムを指定
        $select_expr = '*';
        if (isset($options['select_expr'])) {
            $select_expr = $options['select_expr'];
        }

        // SQL 文の組み立て
        $sql = sprintf('SELECT %s FROM %s WHERE %s', $select_expr, $table, $where);

        // ORDER 句
        if (!is_null($order)) {
            $sql .= sprintf(' ORDER BY %s', $order);
        }

        // LIMIT 句
        if (!is_null($limit)) {
            if (is_array($limit)) {
                $limit = implode(', ', $limit);
            }
            $sql .= sprintf(' LIMIT %s', $limit);
        }

        return $this->rows($sql, $params);
    }

    /**
     * トランザクションを開始する
     *
     * ネストランザクションに対応しています。
     *
     * @return void
     */
    public function begin()
    {
        if (count($this->trans_stack) == 0) {
            $this->query('BEGIN');
            $this->is_uncommitable = false;
        }
        array_push($this->trans_stack, 'A');
    }

    /**
     * トランザクションをコミットする
     *
     * @return void
     * @throws PDOException
     */
    public function commit()
    {
        if (count($this->trans_stack) <= 1) {
            if ($this->is_uncommitable) {
                throw new PDOException('Cannot commit because a nested transaction was rolled back');
            } else {
                $this->query('COMMIT');
            }
        }
        array_pop($this->trans_stack);
    }

    /**
     * トランザクションをロールバックする
     *
     * @return void
     */
    public function rollback()
    {
        if (count($this->trans_stack) <= 1) {
            $this->query('ROLLBACK');
        } else {
            $this->is_uncommitable = true;
        }
        array_pop($this->trans_stack);
    }

    /**
     * 最後に INSERT した行の ID を取得する
     *
     * @param $name
     * @return string 最後に INSERT した行の ID
     */
    public function lastInsertId($name = null)
    {
        return $this->pdo->lastInsertId($name);
    }

    /**
     * 直近の SQL ステートメントで作用した行数を取得する
     *
     * @return int
     */
    public function rowCount()
    {
        return $this->st->rowCount();
    }
}
