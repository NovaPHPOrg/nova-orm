<?php
/*
 * Copyright (c) 2023. Ankio. All Rights Reserved.
 */

/**
 * Package: nova\plugin\orm
 * Class Db
 * Created By ankio.
 * Date : 2022/11/14
 * Time : 23:19
 * Description :
 */

namespace nova\plugin\orm;


use nova\framework\App;
use nova\framework\exception\AppExitException;
use nova\framework\log\Logger;
use nova\framework\request\Response;
use nova\plugin\orm\driver\Driver;
use nova\plugin\orm\exception\DbExecuteError;
use nova\plugin\orm\object\Dao;
use nova\plugin\orm\object\DbFile;
use nova\plugin\orm\object\Model;
use PDO;
use PDOException;
use PDOStatement;
use function nova\framework\config;

class Db
{
    private ?Driver $db = null;


    /**
     * 构造函数
     * @param DbFile $dbFile 数据库配置类
     */
    public function __construct(DbFile $dbFile)
    {
        if (!class_exists("PDO")) {
            throw new AppExitException(Response::asText("Please install PDO extend. https://www.php.net/manual/zh/pdo.installation.php"));
        }

        $driver = "nova\\plugin\\orm\\driver\\" . ucfirst($dbFile->type);
        if (class_exists($driver)) {
            $this->db = new $driver($dbFile);
        } elseif (class_exists($dbFile->type)) {
            //如果此处指定数据库驱动，则尝试去加载
            $driver = $dbFile->type;
            $this->db = new $driver($dbFile);
        } else {
            throw new AppExitException(Response::asText("No driver found for database type: " . $dbFile->type));
        }
    }

    static array $instance = []; //一个配置文件对应一个数据库实例

    /**
     * 使用指定数据库配置初始化数据库连接
     * @param DbFile|null $dbFile
     * @return Db
     */
    public static function getInstance(?DbFile $dbFile = null): Db
    {
        if ($dbFile === null) {
            $dbFile = new DbFile(config('db'));
        }

        $hash = $dbFile->hash();

        if (isset(self::$instance[$hash])) {
            return self::$instance[$hash];
        }

        $instance = new self($dbFile);
        self::$instance[$hash] = $instance;
        return $instance;
    }

    /**
     * 数据表初始化
     * @param Dao $dao
     * @param Model $model
     * @param string $table
     * @return void
     * @throws DbExecuteError
     */
    function initTable(Dao $dao, Model $model, string $table): void
    {

         Logger::Info("create database table : $table ");
        $this->execute($this->db->renderCreateTable($model, $table));
        $dao->onCreateTable();
    }

    /**
     * 数据库执行
     * @param string $sql 需要执行的sql语句
     * @param array $params 绑定的sql参数
     * @param false $readonly 是否为查询
     * @return array|int
     * @throws DbExecuteError
     */
    public function execute(string $sql, array $params = [], bool $readonly = false): int|array
    {
        $GLOBALS['__nova_db_sql_start__'] = microtime(true);

        /**
         * @var $sth PDOStatement
         */

        $connect = $this->db->getDbConnect();


        $sth = $connect->prepare($sql);


        if (!$sth) {
            throw new DbExecuteError(
                sprintf("Sql Prepare Error：%s", $sql),
                $sql
            );
        }

        if (is_array($params) && !empty($params)) foreach ($params as $k => $v) {
            if (is_int($v)) {
                $data_type = PDO::PARAM_INT;
            } elseif (is_bool($v)) {
                $data_type = PDO::PARAM_BOOL;
            } elseif (is_null($v)) {
                $data_type = PDO::PARAM_NULL;
            } else {
                $data_type = PDO::PARAM_STR;
            }

            $sth->bindValue($k, $v, $data_type);
        }
        $ret = null;
        try {
            if ($sth->execute()) {
                $ret = $readonly ? $sth->fetchAll(PDO::FETCH_ASSOC) : $sth->rowCount();
            }
        } catch (PDOException $exception) {
            throw new DbExecuteError(
                sprintf("Run Sql Error：\r\n%s\r\n\r\nError Info：%s",
                    $this->highlightSQL($sql), $exception->getMessage()),
                $sql
            );
        }
        if (App::getInstance()->debug) {
            $end = microtime(true) - $GLOBALS["__nova_db_sql_start__"];
            $t = round($end * 1000, 4);
            Logger::info("sql run time => ". $t . "ms");
        }
        if ($ret !== null) {
            return $ret;
        }
        throw new DbExecuteError(
            sprintf("Run Sql Error：\r\n%s\r\n\r\nError Info：%s",
                $this->highlightSQL($sql), $sth->errorInfo()[2]),
            $sql
        );
    }


    private function highlightSQL($sql): string
    {

        // 定义 SQL 关键词列表
        $keywords = array(
            'SELECT', 'FROM', 'WHERE', 'AND', 'OR', 'NOT', 'IN', 'BETWEEN', 'LIKE',
            'IS', 'NULL', 'AS', 'INNER', 'JOIN', 'LEFT', 'RIGHT', 'OUTER', 'ON',
            'GROUP', 'BY', 'HAVING', 'ORDER', 'LIMIT', 'OFFSET', 'INSERT', 'INTO',
            'VALUES', 'UPDATE', 'SET', 'DELETE', 'TRUNCATE', 'CREATE', 'TABLE',
            'ALTER', 'DROP', 'INDEX', 'VIEW', 'GRANT', 'REVOKE', 'UNION', 'ALL',
            'CASE', 'WHEN', 'THEN', 'ELSE', 'END', 'PRIMARY', 'KEY', 'FOREIGN',
            'REFERENCES', 'CASCADE', 'CONSTRAINT', "IF", "EXISTS", "NOT", "BIGINT", "LONGTEXT", "DEFAULT", "TEXT", "INT", "TINYINT", "FLOAT", "AUTO_INCREMENT", "CHARSET", "ENGINE"
            // 可根据需要添加其他关键词
        );

        // 定义正则表达式模式
        $pattern = '/\b(' . implode('|', $keywords) . ')\b|(\'[^\']*\'|"[^"]*")|\b(\d+)\b|(:[\w]+)|(`[\w]+`)|(--.*)/i';

        // 替换操作
        return preg_replace_callback($pattern, function ($matches) {

            if (!empty($matches[1])) {
                // 关键词
                return '<span style="color: blue;">' . $matches[0] . '</span>';
            } elseif (!empty($matches[2])) {
                // 字符串值
                return '<span style="color: green;">' . $matches[0] . '</span>';
            } elseif (!empty($matches[3])) {
                // 数字值
                return '<span style="color: orange;">' . $matches[0] . '</span>';
            } elseif (!empty($matches[4])) {
                // 参数绑定
                return '<span style="color: purple;">' . $matches[0] . '</span>';
            } elseif (!empty($matches[5])) {
                // 表名和字段名
                return '<span style="color: red;">' . $matches[0] . '</span>';
            } else {
                // 注释
                return '<span style="color: gray;">' . $matches[0] . '</span>';
            }
        }, $sql);
    }


    public function __destruct()
    {
        unset($this->db);
    }

    /**
     * 获取数据库驱动
     * @return Driver|null
     */
    public function getDriver(): ?Driver
    {
        return $this->db;
    }

    /**
     * 导入数据表
     * @param string $sql_path
     * @return void
     */
    public function import(string $sql_path): void
    {
        if (!file_exists($sql_path)) return;

        $file = fopen($sql_path, "r");
        while (!feof($file)) {
            $query = '';
            while (($line = fgets($file)) !== false) {
                // Skip comments and empty lines
                if (trim($line) == '' || str_starts_with($line, '--')) {
                    continue;
                }

                $query .= $line;
                // If the line ends with a semicolon, execute the query
                if (str_ends_with(trim($line), ';')) {
                    try {
                        $this->db->getDbConnect()->exec($query);
                    } catch (PDOException $e) {
                        Logger::error("Db init error : ". $e->getMessage());
                    }
                    // Reset the query string for the next query
                    $query = '';
                }
            }
        }
        fclose($file);
    }

    /**
     * 导出数据表
     * @param ?string $output 输出路径
     * @param bool $only_struct 是否只导出结构
     * @return string
     */
    public function export(string $output = null, bool $only_struct = false): string
    {

        $result = $this->execute("show tables", [], true);
        $tabList = [];
        foreach ($result as $value) {
            $tabList[] = $value["Tables_in_dx"];
        }
        $info = "-- ----------------------------\r\n";
        $info .= "-- Powered by CleanPHP\r\n";
        $info .= "-- ----------------------------\r\n";
        $info .= "-- ----------------------------\r\n";
        $info .= "-- 日期：" . date("Y-m-d H:i:s", time()) . "\r\n";
        $info .= "-- ----------------------------\r\n\r\n";

        foreach ($tabList as $val) {
            $sql = "show create table " . $val;
            $result = $this->execute($sql, [], true);
            $info .= "-- ----------------------------\r\n";
            $info .= "-- Table structure for `" . $val . "`\r\n";
            $info .= "-- ----------------------------\r\n";
            $info .= "DROP TABLE IF EXISTS `" . $val . "`;\r\n";
            $info .= $result[0]["Create Table"] . ";\r\n\r\n";
        }

        if (!$only_struct) {
            foreach ($tabList as $val) {
                $sql = "select * from " . $val;
                $result = $this->execute($sql, [], true);
                if (count($result) < 1) continue;
                $info .= "-- ----------------------------\r\n";
                $info .= "-- Records for `" . $val . "`\r\n";
                $info .= "-- ----------------------------\r\n";

                foreach ($result as $value) {
                    $sqlStr = /** @lang text */
                        "INSERT INTO `" . $val . "` VALUES (";
                    foreach ($value as $k) {
                        $sqlStr .= "'" . $k . "', ";
                    }
                    $sqlStr = substr($sqlStr, 0, strlen($sqlStr) - 2);
                    $sqlStr .= ");\r\n";
                    $info .= $sqlStr;
                }


            }
        }
        if ($output !== null) {
            if(!file_exists(dirname($output))){
                mkdir(dirname($output), 0777, true);
            }
            file_put_contents($output, $info);
        }

        return $info;
    }


}