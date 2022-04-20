<?php
require_once __DIR__ . '/../vendor/autoload.php';
use OKayInc\Trademinator;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\FirePHPHandler;

defined("TRADEMINATOR_ROOTDIR") or define("TRADEMINATOR_ROOTDIR", __DIR__);


		$statements = array(
"CREATE TABLE IF NOT EXISTS my_trades (id TEXT not null PRIMARY KEY, timestamp INTEGER not null, exchange TEXT not null, symbol TEXT not null, side TEXT CHECK( side IN ('buy','sell') ) not null, takerOrMaker TEXT CHECK( takerOrMaker IN ('taker','maker') ) not null, price NUMERIC not null, amount NUMERIC not null, cost NUMERIC not null);",
"CREATE UNIQUE INDEX IF NOT EXISTS timestamp_exchange_symbol ON my_trades(timestamp,exchange,symbol);",
"CREATE TABLE IF NOT EXISTS buys_vs_sells ( buy_id TEXT NOT NULL, sell_id TEXT NOT NULL);",
"CREATE UNIQUE INDEX IF NOT EXISTS buy_id_sell_id on buys_vs_sells(buy_id,sell_id);"
		);

		$db = new \SQLite3('trademinator.db', SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
		$db->enableExceptions(true);
		$db->exec('PRAGMA journal_mode = wal;');
		foreach ($statements as $statement){
			$db->exec($statement);
			if ($db->lastErrorCode()){
				throw new \Exception('Could not execute query: '.$statement);
			}
		}
//		$this->db->exec('COMMIT;');
