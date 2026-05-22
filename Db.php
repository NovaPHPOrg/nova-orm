<?php

/*
 * Copyright (c) 2025. Lorem ipsum dolor sit amet, consectetur adipiscing elit.
 * Morbi non lorem porttitor neque feugiat blandit. Ut vitae ipsum eget quam lacinia accumsan.
 * Etiam sed turpis ac ipsum condimentum fringilla. Maecenas magna.
 * Proin dapibus sapien vel ante. Aliquam erat volutpat. Pellentesque sagittis ligula eget metus.
 * Vestibulum commodo. Ut rhoncus gravida arcu.
 */

declare(strict_types=1);
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

use nova\framework\core\Instance;
use function nova\framework\config;

use nova\framework\core\Context;
use nova\framework\core\Logger;
use nova\framework\exception\AppExitException;

use nova\framework\http\Response;
use nova\plugin\orm\driver\Driver;
use nova\plugin\orm\exception\DbExecuteError;
use nova\plugin\orm\object\Dao;
use nova\plugin\orm\object\DbConfig;
use nova\plugin\orm\object\Model;
use PDO;
use PDOException;
use PDOStatement;

class Db extends Instance
{
    private ?Driver $db = null;

    /**
     * 构造函数
     * @param DbConfig|null $dbFile 数据库配置类
     * @throws AppExitException
     */
    public function __construct(?DbConfig $dbFile)
    {
        if (!class_exists("PDO")) {
            throw new AppExitException(Response::asText("Please install PDO extend. https://www.php.net/manual/zh/pdo.installation.php"));
        }

        if ($dbFile === null) {
            $dbFile = new DbConfig();
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


    /**
     * 数据表初始化
     * @param  Dao    $dao
     * @param  Model  $model
     * @param  string $table
     * @return void
     */
    public function initTable(Dao $dao, Model $model, string $table): void
    {
        Logger::Info("create database table : $table ");
        $this->execute($this->db->renderCreateTable($model, $table));
        $dao->onCreateTable();
    }
    public function buildRunSQL($sql, $params): string
    {
        $sql_default = $sql;
        $params = array_reverse($params);

        foreach ($params as $k => $v) {
            $sql_default = match (gettype($v)) {
                "double", "boolean", "integer" => str_replace($k, strval($v), $sql_default),
                "NULL" => str_replace($k, "NULL", $sql_default),
                default => str_replace($k, "'$v'", $sql_default),
            };
        }
        return $sql_default;
    }

    /**
     * 数据库执行
     * @param  string         $sql      需要执行的sql语句
     * @param  array          $params   绑定的sql参数
     * @param  false          $readonly 是否为查询
     * @return array|int
     * @throws DbExecuteError
     */
    public function execute(string $sql, array $params = [], bool $readonly = false): int|array
    {

        if (Context::instance()->isDebug()) {
            $logSql = $this->buildRunSQL($sql, $params);
            Logger::info("execute $logSql");
        }

        $GLOBALS['__nova_db_sql_start__'] = microtime(true);
        $maxRetries = 3; // 最大重试次数
        $attempts = 0;   // 当前尝试次数

        while ($attempts < $maxRetries) {
            try {
                /**
                 * @var $sth PDOStatement
                 */
                $connect = $this->db->getDbConnect();

                $sth = $connect->prepare($sql);

                if (!$sth) {
                    throw new DbExecuteError(
                        sprintf("Sql Prepare Error：%s", $sql),
                    );
                }

                if (is_array($params) && !empty($params)) {
                    foreach ($params as $k => $v) {
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
                }

                if ($sth->execute()) {
                    $ret = $readonly ? $sth->fetchAll(PDO::FETCH_ASSOC) : $sth->rowCount();

                    if (Context::instance()->isDebug()) {
                        $end = microtime(true) - $GLOBALS["__nova_db_sql_start__"];
                        $t = round($end * 1000, 4);
                        Logger::info("sql run time => ". $t . "ms");
                    }

                    return $ret;
                }

                throw new DbExecuteError(
                    sprintf(
                        "Run Sql Error：\r\n%s\r\n\r\nError Info：%s",
                        $sql,
                        $sth->errorInfo()[2]
                    ),
                    $sql
                );

            } catch (PDOException $exception) {
                $attempts++;

                // 如果是连接丢失错误并且还有重试机会，进行重连
                if ($attempts < $maxRetries &&
                    (str_contains($exception->getMessage(), 'server has gone away') ||
                        str_contains($exception->getMessage(), 'Lost connection') ||
                        str_contains($exception->getMessage(), 'Error connecting') ||
                     $exception->getCode() == 2006 || // server has gone away
                     $exception->getCode() == 2013)) { // lost connection

                    Logger::warning("尝试 $attempts/$maxRetries: 重新连接数据库，原因: " . $exception->getMessage());

                    // 从配置中重新获取数据库配置，创建新的数据库连接
                    $dbFile = new DbConfig();
                    $driver = get_class($this->db);
                    $this->db = new $driver($dbFile);

                    // 等待一段时间后重试
                    usleep(100000 * $attempts); // 100ms, 200ms, 300ms...
                    continue;
                }

                // 如果错误不可恢复或者重试次数已用完
                throw new DbExecuteError(
                    sprintf(
                        "Run Sql Error：\r\n%s\r\n\r\nError Info：%s",
                        $sql,
                        $exception->getMessage()
                    ),
                    $sql
                );
            }
        }

        // 所有重试都失败
        throw new DbExecuteError(
            sprintf(
                "Run Sql Error：\r\n%s\r\n\r\nError Info：重连尝试次数已用完",
                $sql
            ),
            $sql
        );
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
     * @param  string $sql_path
     * @return void
     */
    public function import(string $sql_path): void
    {
        if (!file_exists($sql_path)) {
            return;
        }

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
     * @param  ?string $output      输出路径
     * @param  bool    $only_struct 是否只导出结构
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
        $info .= "-- Powered by NovaPHP\r\n";
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
                if (count($result) < 1) {
                    continue;
                }
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
            if (!file_exists(dirname($output))) {
                mkdir(dirname($output), 0777, true);
            }
            file_put_contents($output, $info);
        }

        return $info;
    }

    public function connection(): PDO
    {
        return $this->db->getDbConnect();
    }

}
