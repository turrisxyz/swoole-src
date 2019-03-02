--TEST--
swoole_mysql_coro: select huge data from db (10M~64M)
--SKIPIF--
<?php
require __DIR__ . '/../include/skipif.inc';
skip_if_in_valgrind();
?>
--FILE--
<?php
require __DIR__ . '/../include/bootstrap.php';
Swoole\Runtime::enableCoroutine();
co::set([
    'socket_timeout' => -1
]);
go(function () {
    $mysql = new Swoole\Coroutine\Mysql;
    $mysql_server = [
        'host' => MYSQL_SERVER_HOST,
        'user' => MYSQL_SERVER_USER,
        'password' => MYSQL_SERVER_PWD,
        'database' => MYSQL_SERVER_DB
    ];
    // set max_allowed_packet
    $mysql->connect($mysql_server);
    if (!$mysql->query('set global max_allowed_packet = 100 * 1024 * 1024')) {
        exit('unable to set max_allowed_packet to 100M.');
    }
    // reconnect and we can see changes
    $mysql->close();
    $mysql->connect($mysql_server);
    @$mysql->query('DROP TABLE `firmware`');
    $ret = $mysql->query(<<<SQL
CREATE TABLE `firmware` (
  `fid`  int(11) NOT NULL AUTO_INCREMENT,
  `firmware` longtext NOT NULL,
  `f_md5` varchar(50) NOT NULL,
  `f_remark` varchar(50) NOT NULL,
  PRIMARY KEY (`fid`)
);
SQL
    );
    if (!$ret) {
        exit('unable to create table.');
    }
    $max_allowed_packet = $mysql->query('show VARIABLES like \'max_allowed_packet\'');
    $max_allowed_packet = $max_allowed_packet[0]['Value'] / 1024 / 1024;
    phpt_var_dump("max_allowed_packet: {$max_allowed_packet}M");
    if (IS_IN_TRAVIS) {
        $max_allowed_packet = 36;
    } else {
        $max_allowed_packet = 64;
    }
    $pdo = new PDO(
        "mysql:host=" . MYSQL_SERVER_HOST . ";dbname=" . MYSQL_SERVER_DB . ";charset=utf8",
        MYSQL_SERVER_USER, MYSQL_SERVER_PWD
    );
    $mysql_query = new Swoole\Coroutine\Mysql;
    $mysql_prepare = new Swoole\Coroutine\Mysql;
    $mysql_query->connect($mysql_server);
    $mysql_prepare->connect($mysql_server);
    for ($fid = 1; $fid <= $max_allowed_packet / 10; $fid++) {
        $random_size = 2 << mt_rand(2, 9);
        $text_size = min($fid * 10 + mt_rand(1, 9), $max_allowed_packet) * 1024 * 1024; // 1xM ~ 5xM
        $firmware = str_repeat(get_safe_random($random_size), $text_size / $random_size);
        $f_md5 = md5($firmware);
        $f_remark = get_safe_random();
        $sql = "INSERT INTO `firmware` (`fid`, `firmware`, `f_md5`, `f_remark`) " .
            "VALUES ({$fid}, '{$firmware}', '{$f_md5}', '{$f_remark}')";
        $ret = $pdo->exec($sql);
        if (assert($ret)) {
            $sql = 'SELECT * FROM `test`.`firmware` WHERE fid=';
            $pdo_stmt = $pdo->prepare("{$sql}?");
            $mysql_stmt = $mysql_prepare->prepare("{$sql}?");
            $chan = new Chan();
            go(function () use ($chan, $pdo_stmt, $fid) {
                $pdo_stmt->execute([$fid]);
                $result = $pdo_stmt->fetch(PDO::FETCH_ASSOC);
                $chan->push(['pdo', $result]);
            });
            go(function () use ($chan, $mysql_stmt, $fid) {
                $result = $mysql_stmt->execute([$fid])[0];
                $chan->push(['mysql_prepare', $result]);
            });
            go(function () use ($chan, $mysql_query, $sql, $fid) {
                $chan->push(['mysql_query', $mysql_query->query("{$sql}{$fid}")[0]]);
            });
            for ($i = 3; $i--;) {
                list($from, $result) = $chan->pop(10);
                Assert::eq($result['fid'], $fid, var_dump_return($result));
                Assert::eq($result['firmware'], $firmware);
                Assert::eq($result['f_md5'], $f_md5);
                Assert::eq($result['f_remark'], $f_remark);
                phpt_var_dump($from, (strlen($firmware) / 1024 / 1024) . 'M');
            }
        }
    }
    $mysql_query->query('DROP TABLE `firmware`');
    echo "DONE\n";
});
?>
--EXPECT--
DONE
