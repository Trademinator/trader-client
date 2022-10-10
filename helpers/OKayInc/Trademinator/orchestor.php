<?php
namespace OKayInc\Trademinator;

use OKayInc;
use OKayInc\Trademinator;

class Orchestor extends \OKayInc\Trademinator{
	private array $clients;
	private \OKayInc\Trademinator\Config $config;
	private array $queue_index;
	private $db;
	private $last_motd;

	function __construct(?string $filename, int $loglevel = \OKayInc\Trademinator::INFO|\OKayInc\Trademinator::NOTICE){
		parent::__construct($loglevel);

		$this->last_motd = null;
		echo $this->colour->convert('%GTrademinator Orchestor '.\OKayInc\Trademinator::$version).PHP_EOL;
		echo $this->colour->convert('%wLoading configuration file...').PHP_EOL;
		$this->config = new \okayinc\trademinator\config($filename);

		$statements = array(
"CREATE TABLE IF NOT EXISTS my_trades (id TEXT NOT NULL PRIMARY KEY, timestamp INTEGER NOT NULL, exchange TEXT NOT NULL, symbol TEXT NOT NULL, side TEXT CHECK( side IN ('buy','sell') ) NOT NULL, takerOrMaker TEXT CHECK( takerOrMaker IN ('taker','maker') ) NOT NULL, price NUMERIC NOT NULL, amount NUMERIC NOT NULL, cost NUMERIC NOT NULL, missing NUMERIC NOT NULL);",
"CREATE UNIQUE INDEX IF NOT EXISTS my_trades_exchange_symbol_timestamp_id ON my_trades(exchange, symbol, timestamp, id);",
"CREATE TABLE IF NOT EXISTS buys_vs_sells ( buy_id TEXT NOT NULL, sell_id TEXT NOT NULL);",
"CREATE UNIQUE INDEX IF NOT EXISTS buy_id_sell_id on buys_vs_sells(buy_id, sell_id);",
"CREATE TABLE IF NOT EXISTS deposits (id TEXT NOT NULL PRIMARY KEY, timestamp INTEGER NOT NULL, exchange TEXT NOT NULL, currency TEXT NOT NULL, prices TEXT, missing NUMERIC NOT NULL);",
"CREATE UNIQUE INDEX IF NOT EXISTS deposits_exchange_symbol_timestamp ON deposits(exchange, currency, timestamp);",
		);

		$this->db = new \SQLite3(TRADEMINATOR_ROOTDIR.'trademinator.db', SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
//		$this->db->open(TRADEMINATOR_ROOTDIR.'trademinator.db');
		$this->db->enableExceptions(true);
		$this->db->exec('PRAGMA journal_mode = wal;');
		$this->db->exec('PRAGMA fullfsync = true;');
		$this->db->exec('PRAGMA checkpoint_fullfsync = true;');
		foreach ($statements as $statement){

			if ($this->loglevel & \OKayInc\Trademinator::DEBUG){
				$_logline = __FILE__.':'.__LINE__.' $statement: '.$statement.PHP_EOL;
				$this->logger->debug($_logline);
				echo $this->colour->convert('%r'.$_logline);
			}
			$this->db->exec($statement);
			if ($this->db->lastErrorCode()){
				throw new \Exception('Could not execute query: '.$statement);
			}
		}
//		$this->db->exec('COMMIT;');

		foreach ($this->config->get_data()['exchanges'] as $exchange_name => $c){
			if ($c['enabled'] == true){
				foreach ($c['symbols'] as $symbol => $sc){
					if ($sc['enabled'] == true){
						if ($this->loglevel & \OKayInc\Trademinator::DEBUG){
							$_logline = __FILE__.':'.__LINE__.' Creating client for '.$symbol.' on '.$exchange_name.PHP_EOL;
							$this->logger->debug($_logline);
							echo $this->colour->convert('%r'.$_logline);
						}
						$_index = $symbol.'@'.$exchange_name;
						$_exchanges[] = $_index;
					}
				}
			}
		}

		// If you are using more than one exchange, this will prevent having to rise your API quota
		shuffle($_exchanges);
		foreach($_exchanges as $_index){
			list($symbol, $exchange_name) = explode('@', $_index);
			$this->clients[$_index] = new \okayinc\trademinator\client($exchange_name, $symbol, $this->config, $loglevel, $this->db);
			$this->queue_index[$_index] = $this->clients[$_index]->get_next_evaluation();
		}
	}

	public function run(?int $times = null){
		if ($this->loglevel & \OKayInc\Trademinator::DEBUG){
			$_logline = __FILE__.':'.__LINE__.' '.__CLASS__.'::'.__METHOD__.'( ?int '.$times.')'.PHP_EOL;
			$this->logger->debug($_logline);
			echo $this->colour->convert('%r'.$_logline);
		}
		$j = 1; $again = true;
		do {
			$this->show_motd();
			$_next =  array_slice($this->queue_index, 0, 1, true);	// Next pair to check
			$_exchange_id = key($_next);
			if ($this->clients[$_exchange_id]->time_passed()){
				echo $this->colour->convert('%wPooling '.$this->clients[$_exchange_id]->get_symbol().' on '.$this->clients[$_exchange_id]->get_exchange_name().'...');
				try{
					$_result = $this->clients[$_exchange_id]->ask();
				}
				catch (Exception $e){
					$this->log_error($e->getMessage().PHP_EOL);
					continue;
				}
				$this->queue_index[$_exchange_id] = $this->clients[$_exchange_id]->get_next_evaluation();
				if ($_result){
					$this->clients[$_exchange_id]->general_profit_report();
				}
				$j++;
			}
			uasort ($this->queue_index , function ($a, $b) {return strnatcmp($a,$b);});
			
			// TODO: insert here Telegram mechanism

			if (is_int($times) && ($j >= $times)){
				$again = false;
				break;
			}
			try{
				$_next =  array_slice($this->queue_index, 0, 1, true);	// Next pair to check
				$_exchange_id = key($_next);
				$_sleep = $this->queue_index[$_exchange_id] - time();
				if ($_sleep < 1){
					$_sleep = 1;
				}
				sleep($_sleep);
			}
			catch (Exception $e){
			}
			echo PHP_EOL;
		} while($again);

		return $j;
	}

	public function operate_exchange(string $command, ...$arguments){

			switch ($command){
				case 'buy':
				case 'sell':
					$exchange_name = $arguments[0];
					$symbol = $arguments[1];
					$price = $arguments[2];
					$amount_xxx = $arguments[3];
					$_index = $symbol.'@'.$exchange_name;
					$function = $command.'_xxx';
					try{
						$new_order = $this->clients[$_index]->$function($amount_xxx, $price);
					}
					catch (Exception $e){
						echo $e->getMessage().PHP_EOL;
					}
			}
	}

	private function show_motd(){
		if ((is_null($this->last_motd)) || ((time() - $this->last_motd) > 14400)){
			$motd = dns_get_record('motd.trademinator.com', DNS_TXT);
			foreach ($motd as $m){
				$this->log_info($m['txt']);
			}
			$this->last_motd = time();
		}
	}
}
