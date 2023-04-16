<?php
namespace OKayInc\Trademinator;

use OKayInc;
use OKayInc\Trademinator;
use ccxt;

class Client extends \OKayInc\Trademinator{

	private \ccxt\Exchange $exchange;
	private string $symbol;
	private \OKayInc\Trademinator\Config $config;
	private $last_ticker;
	private $trading_summary;
	private $balance;
	private $exchange_name;
	private $last_action;
	private $last_timestamp;				// Last verification
	private $my_trades;
	private $my_deposits;
	private ?int $my_trades_last_timestamp;			// in seconds
	private ?int $my_deposits_last_timestamp;			// in seconds
	private $global_state;
	private $open_orders;
	private $base_currency;
	private $market_currency;
	private $average_transaction;
	private $markets;
	private ?int $next_evaluation;
	private $db;
	private ?array $signal;

	public static int $total = 0;
	public static array $total_by_market = [];

	public function __construct(string $exchange_name, string $symbol, \OKayInc\trademinator\config $config, int $loglevel = \OKayInc\Trademinator::INFO|\OKayInc\Trademinator::NOTICE, $db = null){
		parent::__construct($loglevel);

		echo $this->colour->convert('%GTrademinator Client '.\OKayInc\Trademinator::$version.' for '.$symbol.' on '.$exchange_name).PHP_EOL;
		// Load config
		$this->config = clone $config;
		//$this->config->symplify($exchange_name, $symbol);
		$this->config->setExchange_name($exchange_name);
		$this->config->setSymbol($symbol);
		$exchange_full_name = '\\ccxt\\'.$exchange_name;
		$this->exchange = new $exchange_full_name($this->config->get_data()['exchanges'][$exchange_name]);
		$this->symbol = $symbol;
		$this->exchange_name = $exchange_name;
		$this->last_action = null;
		$this->last_timestamp = time();
		list($this->base_currency, $this->market_currency) =  explode('/', $symbol);
		$this->average_transaction = null;
		$this->open_orders = null;
		$this->markets = $this->exchange->load_markets();
		$this->next_evaluation = time();
		$this->my_trades_last_timestamp = null;
		$this->my_deposits_last_timestamp = null;
		$this->signal = null;

		if (!is_null($db)){
			$this->db = &$db;
			$this->migrate_to_db(null);
		}
	}

	public function __destruct(){
		if ($this->db instanceof \Sqlite3){
			$this->db->close();
		}
	}

	public function ask(): bool {
		$this->migrate_to_db(null);
		$this->find_fullfillments();
		$config = $this->config->get_data();
		$exit_code = false; $_logline = '';
		$mode = $this->config->safe_value('mode', 'trademinator');
		$strategy = $this->config->safe_value('strategy', 'memory');
		$url = $this->config->safe_value('url', 'https://signals.trademinator.com');
		$trademinator_url = $url.'/ask.php?exchange='.$this->exchange_name.'&symbol='.$this->symbol.'&period=15m';
		$sell_only = filter_var($this->config->safe_value('sell_only', false), FILTER_VALIDATE_BOOLEAN);

		$_logline = __FILE__.':'.__LINE__.' $trademinator_url: '.$trademinator_url;
		$this->log_debug($_logline);

		$subscription_email = filter_var($this->config->safe_value('subscription_email', ''), FILTER_VALIDATE_EMAIL);
		if ($subscription_email !== false){
			$trademinator_url .= '&email='.urlencode($config[$mode]['subscription_email']);
		}

		$debug = filter_var($this->config->safe_value('debug', false), FILTER_VALIDATE_BOOLEAN);
		if ($debug === true){
			$trademinator_url .= '&debug=1';
		}

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $trademinator_url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Trademinator Client '.\OKayInc\Trademinator::$version);

		do{
			$response = curl_exec($ch);
			//echo $response.PHP_EOL;
		} while(curl_errno($ch) == 28);

		if ($response === false){
			// Exception
			$this->signal = null;
//			throw new \Exception('Could not connect to '.$trademinator_url.'('.curl_error($ch).': '.curl_error($ch).')');
			$_logline = 'Could not connect to '.$trademinator_url.'('.curl_error($ch).': '.curl_error($ch).')';
			$this->log_warning($_logline);
			$signal = null;

		}
		else{
			$_logline = __FILE__.':'.__LINE__.' $response: '.$response;
			$this->log_debug($_logline);
			
			curl_close($ch);
			$signal = json_decode($response, true);
			$this->signal = $signal;				// TODO: change all to point to the variable
			if (is_null($signal)){
				return false;
			}
			$trade_function = $mode.'_signal';

			switch ($signal['action']){
				case 'buy':
					$_colour = '%g';
					break;
				case 'sell':
					$_colour = '%r';
					break;
				default:
					$_colour = '%w';
			}

			echo $this->colour->convert($_colour.strtoupper($signal['action'])).PHP_EOL;

			switch ($signal['action']){
				case 'buy':
					if ($sell_only){
						$_logline = 'BUY condition detected, but only SELLS are allowed in for '.$this->symbol;
						$this->log_warning($_logline);
						$this->record_balances();
						$this->next_evaluation($signal, $exit_code);
						return $exit_code;			
					}
					break;
			}

			$trader_fee = floatval($this->markets[$this->symbol]['taker']) * 100;
			try{
				$this->open_orders = $this->exchange->fetchOpenOrders($this->symbol);

//				$_logline = __FILE__.':'.__LINE__.' $this->open_orders: '.print_r($this->open_orders, true);
//				$this->log_debug($_logline);

				if (count($this->open_orders) > 0){
					// cancel the orders
					$_logline = 'Cancelling pending opened orders.';
					$this->log_notice($_logline);
					if ($this->exchange->safe_value($this->exchange->options,'cancelAllOrders', false)){
						$this->exchange->cancelAllOrders($this->symbol);
					}
					else{
						foreach ($this->open_orders as &$open_order){
							$this->exchange->cancelOrder($open_order['id'], $this->symbol);
						}
					}
				}
			}
			catch (\ccxt\AuthenticationError $e) {
				// handle authentication error here
				$this->log_error($e->getMessage().PHP_EOL);
			}
			catch (\ccxt\NetworkError $e) {
				// your code to handle the network code and retries here
				$this->log_error($e->getMessage().PHP_EOL);
			}
			catch (\ccxt\ExchangeError $e) {
				// your code to handle an exchange error
				$exit_code = false;
				$this->log_error($e->getMessage().PHP_EOL);
			}
			catch(Exception $e) {
				$this->log_error($e->getMessage().PHP_EOL);
			}

			switch ($signal['action']){
				case 'sell':
					try{
						$this->last_ticker = $this->exchange->fetch_ticker($this->symbol);
					}
					catch (\ccxt\AuthenticationError $e) {
						// handle authentication error here
						$this->log_error($e->getMessage().PHP_EOL);
					}
					catch (\ccxt\NetworkError $e) {
						// your code to handle the network code and retries here
						$this->log_error($e->getMessage().PHP_EOL);
					}
					catch (\ccxt\ExchangeError $e) {
						// your code to handle an exchange error
						$exit_code = false;
						$this->log_error($e->getMessage().PHP_EOL);
					}
					catch (\ccxt\ExchangeNotAvailable $e){
						$this->log_error($e->getMessage().PHP_EOL);
					}
					catch(Exception $e) {
						$this->log_error($e->getMessage().PHP_EOL);
					}

//					$_logline = __FILE__.':'.__LINE__.' $this->last_ticker: '.print_r($this->last_ticker, true);
//					$this->log_debug($_logline);

//					$posible_amount = $this->has_a_buy_to_compensate($this->last_ticker['bid']);
//					echo PHP_TAB.$this->colour->convert('%p'.'p:'.$this->last_ticker['bid'].' a:'.$posible_amount).PHP_EOL;
				case 'buy':
					$minimum_points = $this->config->safe_mode('minimum_points', 1);
					if ($signal['points'] >= $minimum_points){
						$exit_code = true;
						try{
							$this->balance = $this->fetch_balance();
							$_logline = 'Balance: '.$this->balance[$this->base_currency]['total'].' '.$this->base_currency.', '.$this->balance[$this->market_currency]['total'].' '.$this->market_currency;
							$this->log_notice($_logline);

//							$_logline = __FILE__.':'.__LINE__.' $this->balance: '.print_r($this->balance, true);
//							$this->log_debug($_logline);

							$since = null; $limit = 1000;
							$this->trading_summary =  $this->trading_summary();
							$this->global_state = $this->global_state();
							$current_state = null;

							if (count($this->trading_summary) > 0){
								$current_state = array_values($this->trading_summary)[0]['side'];
							}

							// ask  -       others selling, me buying
							// bid  -       others buying, me selling
							$market_or_limit = $this->exchange->safe_value($this->exchange->options,'createMarketOrder', false);
							$this->last_ticker = $this->exchange->fetch_ticker($this->symbol);
							$new_order = null;
							if (is_null($current_state)){

								$_logline = 'First signal. No extra verifications.';
								$this->log_notice(PHP_TAB.$_logline);

								// obey the signal
								$amount = $this->calculate_amount($signal['action']);
								$converted_amount = $this->convert_balance($signal['action'], $amount);
								if (!is_null($converted_amount)){
									$new_order = $this->$trade_function(($market_or_limit?'market':'limit'), $signal['action'], $converted_amount, $signal['action'] == 'buy'?$this->last_ticker['ask']:$this->last_ticker['bid']);
									$_logline .= '; null current_state  @'.($current_state=='buy'?$this->last_ticker['ask']:$this->last_ticker['bid']);
								}
								else{
									$_logline .= '; not enought balance.';
								}
							}
							else{
								$this->average_transaction = array_values($this->trading_summary)[0]['average_rate'];

								//$_logline = __FILE__.':'.__LINE__.' $this->average_transaction: '.print_r($this->average_transaction, true);
								//$this->log_debug($_logline);
								$this->get_my_trades(null);
								$last_trade = end($this->my_trades);

								if (	($signal['action'] == 'buy') && 
									($current_state == 'buy') && 
									(floatcmp(floatval($this->last_ticker['ask']), floatval($last_trade['price'])) == -1)
								){
									//(floatval($this->last_ticker['ask']) < floatval($last_trade['price']))){
									// buy, price is still cheaper than the average buying price

									$_logline = $this->symbol.' price('.$this->last_ticker['ask'].') is lower than your average transaction('.$this->average_transaction.') and last purchase('.$last_trade['price'].').';
									$this->log_notice($_logline);

									$amount = $this->calculate_amount('buy');
									$converted_amount = $this->convert_balance('buy', $amount);
									if (!is_null($converted_amount)){
										$new_order = $this->$trade_function(($market_or_limit?'market':'limit'), 'buy', $converted_amount, $signal['action'] == 'buy'?$this->last_ticker['ask']:$this->last_ticker['bid']);
										 $_logline .= '; buy @'.$this->last_ticker['ask']. '; average buy '.$this->average_transaction;
									}
									else{
										$_logline .= '; not enought balance or transaction amount is lower than permited.';
									}
								}
								elseif ($signal['action'] == 'sell' && $current_state == 'buy'){
									if (	($strategy == 'average') && 
										(floatcmp(floatval($this->last_ticker['bid']), floatval($this->average_transaction)) == 1)
									){
										//($this->last_ticker['bid'] > $this->average_transaction)){
										// sell, there is a profit opportunity

										$_logline = $this->symbol.' price('.$this->last_ticker['bid'].') is higher than your average transaction('.$this->average_transaction.').';
										$this->log_notice($_logline);

										// verify profit (if set)
										$earning = floatval(
												bcmul(
													bcdiv(
														bcsub(
															$this->last_ticker['bid'], 
															$this->average_transaction, 
															EXCHANGE_ROUND_DECIMALS), 
														$this->average_transaction, 
														EXCHANGE_ROUND_DECIMALS), 
													100, 
													2)
											);
	
										$minimun_profit_percentage = $this->config->safe_value('minimun_profit_percentage', 1);
										$minimun_profit_percentage2 = $this->minimun_profit_percentage();
//										$this->log_info('Suggested minimum profit percentage: '.$minimun_profit_percentage2);
										if ($minimun_profit_percentage == 'auto'){
											$minimun_profit_percentage = $minimun_profit_percentage2;
										}
										elseif ($minimun_profit_percentage < $minimun_profit_percentage2){
											$this->log_warning('Minimum suggested percentage profit is '.$minimun_profit_percentage2.'%, your current minimum profit is set to '.$minimun_profit_percentage.'%, you may want to adjust or set it to auto.'.PHP_EOL);
										}

										if ($earning <= ($trader_fee * 2)){
											// trader_fee * 2 must be done because the buying part must pay a fee as well
											$exit_code = false;
										}
										elseif (($minimun_profit_percentage !== false) && ($earning < floatval($minimun_profit_percentage))){
											$exit_code = false;
										}
//										elseif( (!is_null($config['trademinator']['minimun_profit_percentage'])) &&
//											($earning < floatval($config['trademinator']['minimun_profit_percentage']))
//											){
//										}
										else{	
											// sell
											$amount = $this->calculate_amount('sell');
											$converted_amount = $this->convert_balance('sell', $amount);
											if (!is_null($converted_amount)){
												$new_order = $this->$trade_function(($market_or_limit?'market':'limit'), 'sell', $converted_amount, $signal['action'] == 'buy'?$this->last_ticker['ask']:$this->last_ticker['bid']);
												$_logline .= '; sell @'.$this->last_ticker['bid']. '; average sell '.$this->average_transaction;
											}
											else{
												$_logline .= '; not enought balance.';
											}
										}
									}
									elseif ($strategy == 'memory'){
										if (($_amount_to_sell = $this->has_a_buy_to_compensate($this->last_ticker['bid'])) > 0){
//			if (($_amount_to_sell > 0) && ($_amount_to_sell >= $minimum_transaction_yyy)){
//											$_amount_to_sell /= $this->last_ticker['bid'];	// price must be in market currency YYY 
											$_amount_to_sell = $this->adjust_xxx($_amount_to_sell);
											$new_order_sell = $this->sell_xxx($_amount_to_sell, $this->last_ticker['bid']);

//											$new_order = $this->$trade_function(($market_or_limit?'market':'limit'), 'sell', $_amount_to_sell, $signal['action'] == 'buy'?$this->last_ticker['ask']:$this->last_ticker['bid']);
											$_logline = 'Sell '. $_amount_to_sell.' @'.$this->last_ticker['bid']. '; memory sell '.$_amount_to_sell;
											$this->log_notice($_logline);
										}
										else{
											$_logline = 'No conditions to operate a sell. You do not any purchase that satisfy a selling price of '.$this->last_ticker['bid']. ' '.$this->symbol;
											$this->log_warning($_logline);
										}
									}
									else{
										$_logline = 'No conditions to operate.';
										$this->log_warning($_logline);
									}
								}
								elseif ($signal['action'] == 'sell' && $current_state == 'sell'){
									if (	($strategy == 'average') && 
										(floatcmp(floatval($this->last_ticker['bid']), floatval($this->average_transaction)) == 1)
									){
										//($this->last_ticker['bid'] > $this->average_transaction)){
										// sell, price has gone up

										$_logline = $this->symbol.' price('.$this->last_ticker['bid'].') is higher than your average transaction('.$this->average_transaction.').';
										$this->log_notice($_logline);

										$amount = $this->calculate_amount('sell');
										$converted_amount = $this->convert_balance('sell', $amount);
										if (!is_null($converted_amount)){
											$new_order = $this->$trade_function(($market_or_limit?'market':'limit'), 'sell', $converted_amount, $signal['action'] == 'buy'?$this->last_ticker['ask']:$this->last_ticker['bid']);
											$_logline .= '; sell @'.$this->last_ticker['bid']. '; average sell '.$this->average_transaction;
										}
										else{
											$_logline .= '; not enought balance.';
										}
									}
									elseif ($strategy == 'memory'){
										$_amount_to_sell = $this->has_a_buy_to_compensate(floatval($this->last_ticker['bid']));
										if (floatcmp(floatval($_amount_to_sell), 0) == 1){
///										($_amount_to_sell = $this->has_a_buy_to_compensate($this->last_ticker['bid'])) > 0){
//										if (($_amount_to_sell > 0) && ($_amount_to_sell >= $minimum_transaction_yyy)){
//											$_amount_to_sell /= $this->last_ticker['bid'];	// price must be in market currency YYY 
											$_amount_to_sell = $this->adjust_xxx($_amount_to_sell);
											$new_order_sell = $this->sell_xxx($_amount_to_sell, $this->last_ticker['bid']);
//											$new_order = $this->$trade_function(($market_or_limit?'market':'limit'), 'sell', $_amount_to_sell, $signal['action'] == 'buy'?$this->last_ticker['ask']:$this->last_ticker['bid']);
											$_logline .= '; sell @'.$this->last_ticker['bid']. '; memory sell '.$_amount_to_sell;
										}
										else{
											$_logline = 'No conditions to operate a sell. You do not any purchase that satisfy a selling price of '.$this->last_ticker['bid']. ' '.$this->symbol;
											$this->log_warning($_logline);
										}
									}
									else{
										$_logline = 'No conditions to operate.';
										$this->log_warning($_logline);
									}
								}
								elseif ($signal['action'] == 'buy' && $current_state == 'sell'){        // Verify && $this->last_ticker['ask'] < $this->average_transaction)
									// buy, price is going down
									$amount = $this->calculate_amount('buy');
									$converted_amount = $this->convert_balance('buy', $amount);
									if (!is_null($converted_amount)){
										$new_order = $this->$trade_function(($market_or_limit?'market':'limit'), 'buy', $converted_amount, $signal['action'] == 'buy'?$this->last_ticker['ask']:$this->last_ticker['bid']);
										$_logline .= '; buy @'.$this->last_ticker['ask']. '; average buy '.$this->average_transaction;
									}
									else{
										$_logline .= 'Not enought balance or below minimum.';
										$this->log_warning($_logline);
									}
								}
								else{
									// No operation conditions
									$exit_code = false;
									$_logline = 'No operation conditions. Signal: '.$signal['action'].', Current state: '.$current_state.', Average Transaction: '.$this->average_transaction;
									$this->log_warning($_logline);
								}
							}

							if (!is_null($new_order)){
								$_logline = __FILE__.':'.__LINE__.' $new_order: '.print_r($new_order, true);
								$this->log_debug($_logline);
							}
						}
						catch (\ccxt\AuthenticationError $e) {
							// handle authentication error here
							$this->log_error($e->getMessage());
						}
						catch (\ccxt\NetworkError $e) {
							// your code to handle the network code and retries here
							$this->log_error($e->getMessage());
						}
						catch (\ccxt\ExchangeError $e) {
							// your code to handle an exchange error
							$exit_code = false;
							$this->log_error($e->getMessage());
						}
						catch(Exception $e) {
							$this->log_error($e->getMessage());
						}
					}
					else{
						// Not enough points
						$_logline = 'Not enough signal points, minimum is '.$minimum_points. ', you got '.$signal['points'];
						$this->log_warning($_logline);
					}
					break;
				default:
			}

			$this->record_balances();
			$this->offline($signal);
			$this->report();

			list ($excess_xxx, $suggested_price_xxx) = $this->find_excess();
			if ($excess_xxx > 0){
				$_logline = 'You can manually sell '.$excess_xxx.' '.$this->base_currency.' at a suggested price of '.$suggested_price_xxx.' '.$this->symbol.'.';
				$this->log_notice($_logline);
			}

			$this->last_action = $signal['action'];
			$this->last_timestamp = time();
		}

		$this->next_evaluation($signal, $exit_code);
		return $exit_code;			
	}

	private function next_evaluation(?array $signal, $exit_code = false): int{
		if (is_null($signal) || ($signal['next_evaluation'] <= time())){
			$config = $this->config->get_data();
			//$mode = $config['mode'];
			$mode = $this->config->safe_value('mode', 'trademinator');
			if (($this->last_action == 'hodl') || ($exit_code == false)){
				$this->next_evaluation = time() + $this->config->safe_value('minimum_non_operation_space_in_seconds', 300);
			}
			else{
				$this->next_evaluation = time() + $this->config->safe_value('minimum_operation_space_in_seconds', 600);
			}
		}
		else {
			$this->next_evaluation = $signal['next_evaluation'];
		}

		return $this->next_evaluation;
	}

	public function trading_summary(){
		$status_answer = null; $average_rate = null; $total_sells = 0; $total_buys = 0; $global_average_rate = 0; $global_sell_rate = 0; $global_buy_rate = 0;
		$states = array(); $global_state['average_rate'] = 0;
		try{
			//$this->my_trades = $this->exchange->fetch_my_trades($this->symbol, null, 999);	// index 0 is the first trade
			$this->get_my_trades(null);

			$_logline = __FILE__.':'.__LINE__.' $this->my_trades: '.print_r($this->my_trades, true);
			$this->log_debug($_logline);

			if (($last_trade = end($this->my_trades)) != false){
				$status_answer = $last_trade['side'];
				$decimals = 6;
				$i = count($this->my_trades) - 1; $j = $i; $k = 1;

				do{
					if ($last_trade['side'] != $this->my_trades[$i]['side']){
						$j = $i; $k = 1;
					}

					if (!isset($states[$j]['sold'])){
						$states[$j]['sold'] = 0;
					}

					if (!isset($states[$j]['bought'])){
						$states[$j]['bought'] = 0;
					}

					$states[$j]['side'] = $this->my_trades[$i]['side'];
					$states[$j]['sold'] += floatval(number_format($this->my_trades[$i]['amount'],$decimals,'.',''));
					$states[$j]['bought'] += floatval(number_format($this->my_trades[$i]['cost'],$decimals,'.',''));
					$states[$j]['consecutive'] = $k;
					
					$last_trade = $this->my_trades[$i]; $k++;
				}while(--$i >=0);

				foreach ($states as &$state){
					$state['average_rate'] = $state['bought'] / $state['sold'];
				}
			}
		}
		catch (\ccxt\AuthenticationError $e) {
			// handle authentication error here
			$this->log_error($e->getMessage());
		}
		catch (\ccxt\NetworkError $e) {
			// your code to handle the network code and retries here
			$this->log_error($e->getMessage());
		}
		catch (\ccxt\ExchangeError $e) {
			// your code to handle an exchange error
			$this->log_error($e->getMessage());
		}
		catch(Exception $e) {
			$this->log_error($e->getMessage());
		}

		$this->trading_summary = $states;

		$_logline = __FILE__.':'.__LINE__.' $states: '.print_r($states, true);
		$this->log_debug($_logline);

		return $states;
	}

	public function global_state(){
		$this->get_my_trades(null);	// 0 is the first trade

		if (!is_array($this->trading_summary) || (count($this->trading_summary) == 0)){
			$this->trading_summary = $this->trading_summary();
		}

		$total_sells = 0; $total_buys = 0;
		if (($last_trade = end($this->my_trades)) != false){
			$global_state['last_trade']['side'] = $last_trade['side'];
			$global_state['last_trade']['rate'] = $last_trade['price'];
			$status_answer = $last_trade['side'];
			$decimals = 6;
			$i = count($this->my_trades) - 1; $j = $i; $k = 1;

			do{
				if ($last_trade['side'] != $this->my_trades[$i]['side']){
					$j = $i; $k = 1;
				}

				if (!isset($states[$j]['sold'])){
					$states[$j]['sold'] = 0;
				}

				if (!isset($states[$j]['bought'])){
					$states[$j]['bought'] = 0;
				}

				$states[$j]['side'] = $this->my_trades[$i]['side'];
				$states[$j]['sold'] += floatval(number_format($this->my_trades[$i]['amount'],$decimals,'.',''));
				$states[$j]['bought'] += floatval(number_format($this->my_trades[$i]['cost'],$decimals,'.',''));
				$states[$j]['consecutive'] = $k;
					
				$total_sells += floatval(number_format($this->my_trades[$i]['amount'],$decimals,'.',''));
				$total_buys += floatval(number_format($this->my_trades[$i]['cost'],$decimals,'.',''));
				$last_trade = $this->my_trades[$i]; $k++;
			}while(--$i >=0);

			foreach ($states as &$state){
				$state['average_rate'] = $state['bought'] / $state['sold'];
				}

			$global_average_rate = $total_buys / $total_sells;
			$global_state['average_rate'] = $global_average_rate;
		}

		// $states has the summary, lets do some statistics
		$_states = $this->trading_summary;
		$max_buying_consecutives = 1; $max_selling_consecutives = 1; $total_buys = 0; $total_sells = 0; $ii = 0; $jj = 0; $sellings_sold = 0; $sellings_bought = 0; $buyings_sold = 0; $buyings_bought = 0;
		$sellings = array(); $buyings = array();
		foreach ($_states as $state){
			if (strcmp($state['side'],'sell') == 0){
				$max_selling_consecutives = max($max_selling_consecutives, $state['consecutive']);
				$sellings[] = $state['consecutive'];
				$sellings_sold += $state['sold'];
				$sellings_bought += $state['bought']; 
				$ii++;
			}                
			elseif (strcmp($state['side'],'buy') == 0){
				$max_buying_consecutives = max($max_buying_consecutives, $state['consecutive']);
				$buyings[] = $state['consecutive'];
				$buyings_sold += $state['sold'];
				$buyings_bought += $state['bought']; 
				$jj++;
			}
		}

		// $buyings and $sellings have the data to get the standard deviation
		if ($sellings_sold > 0){
			$global_state['average_sellings_rate'] = $sellings_bought / $sellings_sold;
		}
		else{
			$global_state['average_sellings_rate'] = 0;
		}
		$global_state['sellings_stddev'] = (count($sellings) > 0)?stddev($sellings):0;

		if ($buyings_sold > 0){
			$global_state['average_buyings_rate'] = $buyings_bought / $buyings_sold;
		}
		else{
			$global_state['average_buyings_rate'] = 0;
		}

		$global_state['buyings_stddev'] = (count($buyings) > 0)?stddev($buyings):0;
		$this->global_state = $global_state;

		$_logline = __FILE__.':'.__LINE__.' $global_state: '.print_r($global_state, true);
		$this->log_debug($_logline);

		$_logline = __FILE__.':'.__LINE__.' $this->trading_summary: '.print_r($this->trading_summary, true).PHP_EOL;
		$_logline .= '$this->trading_global_state: '.print_r($this->global_state, true);
		$this->log_debug($_logline);

		return $global_state;
	}

	// symbol = XXX/YYY
	public function calculate_amount($side){
		$amount = null;
		$strategy = $this->config->safe_value('strategy', 'memory');

		if (count($this->trading_summary) == 0){
			$this->trading_summary = $this->trading_summary();
		}

		if (!is_array($this->global_state) || (count($this->global_state) == 0)){
			$this->global_state = $this->global_state();
		}

		if (count($this->last_ticker) == 0){
			$this->last_ticker = $this->exchange->fetch_ticker($this->symbol);

			$_logline = __FILE__.':'.__LINE__.' $this->last_ticker: '.print_r($this->last_ticker, true);
			$this->log_debug($_logline);
		}

		if (count($this->balance) == 0){
			$this->balance = $this->fetch_balance();

			$_logline = __FILE__.':'.__LINE__.' $this->balance: '.print_r($this->balance, true);
			$this->log_debug($_logline);
		}

		$cc = $this->base_currency; $switch_action = false;
		if ((count($this->trading_summary) > 0) && ($this->last_trading = array_values($this->trading_summary)[0]) != false){
			// Calculate based on the displacement

			if ($this->last_trading['side'] == $side){
				// Buy or sell more
				// ask  -       others selling, me buying
				// bid  -       others buying, me selling

				if ($this->last_trading['side'] == 'sell'){
					$difference = $this->last_ticker['bid'] - $this->last_trading['average_rate'];
					if ($difference < 0){
						$difference = 0;
					}
				}
				else{
					$difference = $this->last_ticker['ask'] - $this->last_trading['average_rate'];
					$cc = $this->market_currency;
					if ($difference > 0){
						$difference = 0;
					}
					$difference = abs($difference);
				}
			}
			else{
				$switch_action = true;
				// Side changed
				if ($this->last_trading['side'] == 'sell'){
					// I sold, now I buy
					$difference = abs($this->last_ticker['ask'] - $this->last_trading['average_rate']);
					$cc = $this->market_currency;
				}
				else{
					// I bought, now I sell
					$difference = abs($this->last_ticker['bid'] - $this->last_trading['average_rate']);
					if ($difference < 0){
						// Price has decreased, it is not a business if we sell cheaper // TODO: review this logic
						$difference = 0;
					}
				}
			}

			$consecutive = $switch_action?1:$this->last_trading['consecutive'];
			if ($side == 'buy'){
				// We buy conservative
				$factor = 6;

				if ($strategy == 'average'){
					if (($this->global_state['last_trade']['side'] == 'buy') && ($this->last_ticker['ask'] < $this->global_state['average_buyings_rate'])){
						// if buying price is lower than the global average buying rate, we buy more
						$factor *= 1.5;
					}
				}

				// Low the average buying price
				if (($this->global_state['last_trade']['side'] == 'buy') && ($this->last_ticker['ask'] < $this->global_state['last_trade']['rate'])){
					// if buying price is lower than last_ticker buying price, we buy more
					// must be second and beyond purchase
					$factor *= 1.5;
				}

				$percentage = $factor * ($difference / $this->last_trading['average_rate']) * $consecutive;

				$_logline = __FILE__.':'.__LINE__.' $percentage = $factor('.$factor.') * ($difference('.$difference.') / $this->last_trading[average_rate]('.$this->last_trading['average_rate'].')) * $consecutive('.$consecutive.') = '.$percentage;
				$this->log_debug($_logline);
			}
			else{
				// We sell more agresive
				$percentage = 0.85;

				if (($this->global_state['last_trade']['side'] == 'sell') && ($this->last_ticker['bid'] > $this->global_state['last_trade']['rate'])){
					// if buying price is lower than last_ticker buying price, we buy more
					// must be second and beyond purchase
					$percentage *= 1.03;
				}

				if (($this->global_state['last_trade']['side'] == 'sell') && ($this->last_ticker['bid'] > $this->global_state['average_sellings_rate'])){
					// if buying price is lower than the global average buying rate, we buy more
					// must be second and beyond sell
					$percentage *= 1.03;
				}

				$_logline = __FILE__.':'.__LINE__.' $percentage = '.$percentage;
				$this->log_debug($_logline);
			}

			$amount = $this->balance[$cc]['free'] * $percentage;  // TODO: find a better way

			$_logline = __FILE__.':'.__LINE__.' $amount = $this->balance[$cc][free]('.$this->balance[$cc]['free'].') * $percentage('.$percentage.') = '.$amount;
			$this->log_debug($_logline);
		}
		else{
			 // There is no previous trading activity, use a fixed formula instead
			if ($side == 'buy'){
				$cc = $this->market_currency;
			}
			$amount = $this->balance[$cc]['free'] * 0.10; // Start with 10%, first iteractions are not always the best
			$percentage = 0;

			$_logline = __FILE__.':'.__LINE__.' $amount = $this->balance[$cc][free]('.$this->balance[$cc]['free'].') * 0.10 = '.$amount;
			$this->log_debug($_logline);
		}

		// $amount is in market currency, we haven't verified if the minimum is enough
		// log report
		return $amount;
	}

	// This function modifies the balance and transforms it (if needed). Rules must be configured here.
	public function convert_balance($side, $amount){
		$answer = null;

		if (count($this->last_ticker) == 0){
			$this->last_ticker = $this->exchange->fetch_ticker($this->symbol);

			$_logline = __FILE__.':'.__LINE__.' $this->last_ticker: '.print_r($this->last_ticker, true);
			$this->log_debug($_logline);
		}

		if (count($this->balance) == 0){
			$this->balance = $this->fetch_balance();

			$_logline = __FILE__.':'.__LINE__.' $this->balance: '.print_r($this->balance, true);
			$this->log_debug($_logline);
		}

		if (count($this->trading_summary) == 0){
			$this->trading_summary = $this->trading_summary();
		}

		if (!is_array($this->global_state) || (count($this->global_state) == 0)){
			$this->global_state = $this->global_state();
		}

		$requires_price = $this->exchange->safe_value($this->exchange->options, 'createMarketBuyOrderRequiresPrice', false);

		$_logline = __FILE__.':'.__LINE__.' $requires_price: '.(int)$requires_price;
		$this->log_debug($_logline);

		switch ($side){
			case 'buy':
				$amount_yyy = $amount;
				$amount_xxx = $amount_yyy / $this->last_ticker['ask'];

				$_logline = __FILE__.':'.__LINE__.' $amount_yyy = $amount('.$amount.') = '.$amount_yyy.'; $amount_xxx = $amount_yyy('.$amount_yyy.') / $this->last_ticker[ask]('.$this->last_ticker['ask'].') = '.$amount_xxx;
				$this->log_debug($_logline);
				break;
			case 'sell':
				$amount_xxx = $amount;
				$amount_yyy = $amount_xxx * $this->last_ticker['bid'];

				$_logline = __FILE__.':'.__LINE__.' $amount_xxx = $amount('.$amount.') = '.$amount_xxx.'; $amount_yyy = $amount_xxx('.$amount_xxx.') * $this->last_ticker[bid]('.$this->last_ticker['bid'].') = '.$amount_yyy;
				$this->log_debug($_logline);
				break;
			default:
				// This scenario is only for tests
				$amount_xxx = $amount;
				$amount_yyy = $amount;

				$_logline = __FILE__.':'.__LINE__.' $amount_xxx = $amount('.$amount.') = '.$amount_xxx.'; $amount_yyy = $amount('.$amount.') = '.$amount_yyy;
				$this->log_debug($_logline);
		}

		$minimum_transaction_yyy = floatval($this->markets[$this->symbol]['limits']['cost']['min'])*1.01;   // market currency, 1% because rounding may lower it
		$minimum_transaction_xxx = ($this->base_currency == 'USDT')?1:0.0001;  // TODO find a better way
		if (!is_null($this->markets[$this->symbol]['limits']['amount']['min'])){
			$minimum_transaction_xxx = floatval($this->markets[$this->symbol]['limits']['amount']['min']) * 1.01;               // base currency, 1% because rounding may lower it
		}

		$_logline = __FILE__.':'.__LINE__.' $minimum_transaction_xxx = '.$minimum_transaction_xxx.'; $minimum_transaction_yyy = '.$minimum_transaction_yyy;
		$this->log_debug($_logline);

		switch ($side){
			case 'buy':
				// Check if there is enough balance on the $market currency as $amount_yyy is given in $base
				// ask  -       others selling, me buying
				// bid  -       others buying, me selling
				// No need to convert, just check balance
				if (floatval($amount_yyy) <= floatval($this->balance[$this->market_currency]['free'])){
					// There is enough balance :)
					$_logline = 'You have enough '.$this->market_currency.' balance: '.$this->balance[$this->market_currency]['free'];
					$this->log_notice($_logline);

					// Checks if there is no dust left
					$left_yyy = $this->balance[$this->market_currency]['free'] - $amount_yyy;
					if ($left_yyy < $minimum_transaction_yyy){
						$amount_yyy = $this->balance[$this->market_currency]['free'];

						$_logline = __FILE__.':'.__LINE__.'(antidust) $amount_yyy = $this->balance[$this->market_currency][free]('.$this->balance[$this->market_currency]['free'].') = '.$amount_yyy;
						$this->log_debug($_logline);
					}
				}
				else{
					// Lets use all we have
					$amount_yyy = $this->balance[$this->market_currency]['free'];
					$amount_xxx = $amount_yyy / $this->last_ticker['ask'];

					$_logline = __FILE__.':'.__LINE__.'(antidust) $amount_yyy = $this->balance[$this->market_currency][free]('.$this->balance[$this->market_currency]['free'].') = '.$amount_yyy;
					$this->log_debug($_logline);
				}
				break;
			case 'sell':
				if (floatval($amount_xxx) <= floatval($this->balance[$this->base_currency]['free'])){
					// There is enough balance :)

					$_logline = 'You have enough '.$this->base_currency.' balance: '.$this->balance[$this->base_currency]['free'];
					$this->log_notice($_logline);

					// Checks if there is no dust left
					$left_xxx = $this->balance[$this->base_currency]['free'] - $amount_xxx;
					if ($left_xxx < $minimum_transaction_xxx){
						$amount_xxx = $this->balance[$this->base_currency]['free'];
						$_logline = __FILE__.':'.__LINE__.'(antidust) $amount_xxx = $this->balance[$this->base_currency][free]('.$this->balance[$this->base_currency]['free'].') = '.$amount_xxx;
						$this->log_debug($_logline);
					}
				}
				else{
					// Lets use all we have
					$amount_xxx = $this->balance[$this->base_currency]['free'];
					$amount_yyy = $amount_xxx * $this->last_ticker['bid'];
					$_logline = __FILE__.':'.__LINE__.'(antidust) $amount_xxx = $this->balance[$this->base_currency][free]('.$this->balance[$this->base_currency]['free'].') = '.$amount_xxx;
					$this->log_debug($_logline);
				}
				break;
			default:
				$answer = $amount_yyy;
		}

		// Run it twice to make sure we are over the minimums
		for ($ii = 0; $ii < 2; $ii++){
			if (($minimum_transaction_yyy > 0) && ($amount_yyy < $minimum_transaction_yyy)){
				// We can't operate
				$amount_yyy = $minimum_transaction_yyy;
				$amount_xxx = $amount_yyy / ($side=='buy'?$this->last_ticker['ask']:$this->last_ticker['bid']);

				$_logline = $ii.': Adjusting to '.$this->exchange_name.' '.$this->market_currency.' minimum of '.$minimum_transaction_yyy;
				$this->log_notice($_logline);
			}
			if (($minimum_transaction_xxx > 0) && ($amount_xxx < $minimum_transaction_xxx)){
				// We can't operate
				$amount_xxx = $minimum_transaction_xxx;
				$amount_yyy = $amount_xxx * ($side=='buy'?$this->last_ticker['ask']:$this->last_ticker['bid']);

				$_logline = $ii.': Adjusting to '.$this->exchange_name.' '.$this->base_currency.' minimum of '.$minimum_transaction_xxx;
				$this->log_notice($_logline);
			}
		}

		// TODO: Veriy, works with gateio
		if ($requires_price){
			$answer = $amount_yyy;
		}
		else{
			$answer = $amount_xxx;  // gateio buying answer is expected in XXX
		}

		return $answer;
	}

	public function time_passed(): bool{
		$config = $this->config->get_data();
		$mode = $this->config->safe_value('mode', 'trademinator');
		$answer = false;

		if (is_null($this->last_action)){
			// First time
			$answer = true;
		}
		else{
			if ($this->next_evaluation < time()){
				$answer = true;
			}
		}
		return $answer;
	}

	private function getProperty(string $property, $default = null){
		return $this->$property ?? $default;
	}

	public function __call($method, $args){
		//Convert method to snake_case (which is the name of the property)
		$property_name = mb_strtolower(ltrim(preg_replace('/[A-Z]/', '_$0', substr($method, 3)), '_'));
		$action = substr($method, 0, 3);

		if ($action === 'get') {
			$property = $this->getProperty($property_name);
			return $property;
		}
		elseif ($action === 'set') {
			// Limit setters to specific classes.
			$this->$property_name = $args[0];
			return $this;
		}
		return null;
        }

	private function octobot_signal($type, $side, $amount = null, $price = null){
		$config = $this->config->get_data();
		$dry = false;

		// curl -v  -X POST -H 'Content-Type: text/plain; charset=utf-8'  -d 'TOKEN=MYHASH
		//EXCHANGE=gateio
		//SYMBOL=XMRUSDT
		//SIGNAL=BUY
		//ORDER_TYPE=MARKET' http://192.168.7.108:9001/webhook/trading_view
		$side = strtoupper($side);
		$exchange_name = $this->exchange->id;
		list($c, $m) = explode('/', urldecode($this->symbol));
		$payload = '';
		$token = $this->config->safe_value('token', null);
		if (!is_null($token)){
			$payload = 'TOKEN='.$token.PHP_EOL;
		}

		$payload .= "EXCHANGE=$exchange_name
SYMBOL=$c$m
SIGNAL=$side
ORDER_TYPE=$type";
		if (!is_null($amount)){
			$payload .= "
VOLUME=$amount";
		}
		if (!is_null($price)){
			$payload .= "
PRICE=$price";
		}

		$headers = array(
			"Content-Type: application/json",
		);

		$ch = curl_init();
		$url = $this->config->safe_value('url', 'http://127.0.0.1:9001/webhook/trading_view');
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Trademinator Client 1.0');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		// TODO: Review if this is still valid
		$minimum_operation_space_in_seconds = $this->config->safe_value('minimum_operation_space_in_seconds', 600);
		if ($this->exchange->safe_value($this->exchange->options,'fetchMyTrades', false) && (intval($config['trademinator']['minimum_operation_space_in_seconds']) > 0)) {
			// $this->my_trades = $this->exchange->fetch_my_trades($this->symbol, $since, $limit, []);
			$this->get_my_trades(null);
			$last_trade = end($this->my_trades);
			if ($last_trade != false){
				$time_passed = (time() * 1000) - $last_trade['timestamp'];
				if ($time_passed < $minimum_operation_space_in_seconds){
					$dry = true;
				}
			}
		}

		if (!$dry){
			$response = curl_exec($ch);
		}
		if ($response === false){
			throw new \Exception('Could connect to '.$url.'('.curl_error($ch).': '.curl_error($ch).')');
		}
		else{
			curl_close($ch);

			$_logline = 'Signal sent to Octobot.';
			$this->log_notice($_logline);
		}

		return $response;
	}

	private function trademinator_signal($type, $side, $amount = null, $price = null){
		$config = $this->config->get_data();
		$dry = false;
		$answer = null;
		if (is_null($amount)){
			list($c, $m) = explode('/', urldecode($this->symbol));
			$this->balance = $this->fetch_balance(); $cc = $m;
			if ($side = 'sell'){
				$cc = $c;
			}
			$risk_percentage = $this->config->safe_value('risk_percentage', 25);
			$amount = $this->balance[$cc]['free'] * $risk_percentage / 100;	// TODO create a dynamic amount based on risk
		}

		// TODO: Review if this is still valid
		$minimum_operation_space_in_seconds = $this->config->safe_value('minimum_operation_space_in_seconds', 600);
		if (($this->exchange->has['fetchMyTrades']) && (intval($minimum_operation_space_in_seconds) > 0)) {
			$limit = 999; $since = null;
			// $my_trades = $this->exchange->fetch_my_trades($this->symbol, $since, $limit, []);
			$this->get_my_trades(null);
			$last_trade = end($this->my_trades);
			if ($last_trade != false){
				$time_passed = (time() * 1000) - $last_trade['timestamp'];
				if ($time_passed < $minimum_operation_space_in_seconds){
					$dry = true;
				}
			}
		}

		// Check if order $type is supported, otherwise change it
		if (!$dry){
			try{
				$answer = $this->exchange->create_order($this->symbol, $type, $side, $amount, $price);
			}
			catch (\ccxt\AuthenticationError $e) {
				$this->log_error($e->getMessage());
			}
			catch (\ccxt\NetworkError $e) {
				$this->log_error($e->getMessage());
			}
			catch (\ccxt\ExchangeError $e) {
				$this->log_error($e->getMessage());
			}
			catch(Exception $e) {
				$this->log_error($e->getMessage());
			}
		}
		return $answer;
	}

	public function sell_xxx(float $amount, ?float $price){
//		echo "function sell_xxx(float $amount, ?float $price)".PHP_EOL;
		// amount is in base currency YYY
		$config = $this->config->get_data();
		$mode = $this->config->safe_value('mode', 'trademinator');
		$trade_function = $mode.'_signal';
		$requires_price = $this->exchange->safe_value($this->exchange->options, 'createMarketBuyOrderRequiresPrice', false);
		if ($requires_price){
			// if price must be in market currency YYY
			if (is_null($price)){
				$this->last_ticker = $this->exchange->fetch_ticker($this->symbol);
				$p = $this->last_ticker['bid'];

				$_logline = __FILE__.':'.__LINE__.' $p = $this->last_ticker[bid] = '.$p;
				$this->log_debug($_logline);
			}
			else{
				$p = $price;

				$_logline = __FILE__.':'.__LINE__.' $p = $price = '.$p;
				$this->log_debug($_logline);
			}
			$amount *= $p;
		}
		else{
			$p = $price;
		}

		$_logline = __FILE__.':'.__LINE__.' $amount = '.$amount;
		$this->log_debug($_logline);

		$new_order = $this->$trade_function('limit', 'sell', $amount, $p);
		return $new_order;
	}

	public function buy_xxx(float $amount, ?float $price){
//		echo "function buy_xxx(float $amount, ?float $price)".PHP_EOL;
		// amount is in base currency YYY
		$config = $this->config->get_data();
		$mode = $this->config->safe_value('mode', 'trademinator');
		$trade_function = $mode.'_signal';
		$requires_price = $this->exchange->safe_value($this->exchange->options, 'createMarketBuyOrderRequiresPrice', false);

		if ($requires_price){
			// if price must be in market currency YYY
			if (is_null($price)){
				$this->last_ticker = $this->exchange->fetch_ticker($this->symbol);
				$p = $this->last_ticker['bid'];
			}
			else{
				$p = $price;
			}
			$amount /= $p;
		}
		else{
			$p = $price;
		}

		$_logline = __FILE__.':'.__LINE__.' $amount = '.$amount;
		$this->log_debug($_logline);

		$new_order = $this->$trade_function('limit', 'buy', $amount, $p);
		return $new_order;
	}

	public function sell_yyy(float $amount, ?float $price){
//		echo "function sell_yyy(float $amount, ?float $price)".PHP_EOL;
		// amount is in market currency YYY
		$config = $this->config->get_data();
		$mode = $this->config->safe_value('mode', 'trademinator');
		$trade_function = $mode.'_signal';
		$requires_price = $this->exchange->safe_value($this->exchange->options, 'createMarketBuyOrderRequiresPrice', false);
		if (!$requires_price){
			// if price must be in market currency YYY
			if (is_null($price)){
				$this->last_ticker = $this->exchange->fetch_ticker($this->symbol);
				$p = $this->last_ticker['bid'];

				$_logline = __FILE__.':'.__LINE__.' $p = $this->last_ticker[bid] = '.$p;
				$this->log_debug($_logline);
			}
			else{
				$p = $price;

				$_logline = __FILE__.':'.__LINE__.' $p = $price = '.$p;
				$this->log_debug($_logline);
			}
		}
		else{
			$p = $price;
			$amount /= $price;	// Needs to be in quote currency XXX
		}

		$_logline = __FILE__.':'.__LINE__.' $amount = '.$amount;
		$this->log_debug($_logline);

		$new_order = $this->$trade_function('limit', 'sell', $amount, $p);
		return $new_order;
	}

	public function buy_yyy(float $amount, ?float $price){
//		echo "function buy_yyy(float $amount, ?float $price)".PHP_EOL;
		// amount is in base market currency YYY
		$config = $this->config->get_data();
		$mode = $this->config->safe_value('mode', 'trademinator');
		$trade_function = $mode.'_signal';
		$requires_price = $this->exchange->safe_value($this->exchange->options, 'createMarketBuyOrderRequiresPrice', false);

		if (!$requires_price){
			// if amount must be in market currency YYY
			if (is_null($price)){
				$this->last_ticker = $this->exchange->fetch_ticker($this->symbol);
				$p = $this->last_ticker['bid'];
			}
			else{
				$p = $price;
			}
		}
		else{
			$p = $price;
			$amount /= $price;	// Needs to be in quote currency XXX
		}

		$_logline = __FILE__.':'.__LINE__.' $amount = '.$amount;
		$this->log_debug($_logline);

		$new_order = $this->$trade_function('limit', 'buy', $amount, $p);
		return $new_order;
	}

	// This function calculates the minimum selling price taking in account all wallet factors
	public function offline(array $signal){
		$config = $this->config->get_data();
		$trader_fee = floatval($this->markets[$this->symbol]['taker']) + floatval($this->markets[$this->symbol]['maker']);				// A sell and a bought	50% => 0.50, 0.20% => 0.0020
		$min_profit_config = floatval($this->config->safe_value('minimun_profit_percentage', 1))/100;	// 1% => 0.01
		$minimum_profit = 1 + max($trader_fee, $min_profit_config);
		$sell_only = filter_var($this->config->safe_value('sell_only', false), FILTER_VALIDATE_BOOLEAN);

		if (!is_array($this->trading_summary) || (count($this->trading_summary) == 0)){
			$this->trading_summary = $this->trading_summary();
		}
		if (isset($this->trading_summary[0])){
			$this->average_transaction = array_values($this->trading_summary)[0]['average_rate'];
		}
		else{
			$this->average_transaction = 0;
		}

		// ask  -       others selling, me buying
		// bid  -       others buying, me selling
		$again = false;
		do{
			try{
				$this->last_ticker = $this->exchange->fetch_ticker($this->symbol);
				$this->balance = $this->fetch_balance();
				$again = false;
			}
			catch (\ccxt\AuthenticationError $e) {
				$this->log_error($e->getMessage().PHP_EOL);
				$again = true;
			}
			catch (\ccxt\NetworkError $e) {
				$this->log_error($e->getMessage().PHP_EOL);
				$again = true;
			}
			catch (\ccxt\ExchangeError $e) {
				$this->log_error($e->getMessage().PHP_EOL);
				$again = true;
			}
			catch(Exception $e) {
				$this->log_error($e->getMessage().PHP_EOL);
				$again = true;
			}
		} while($again);
//echo print_r($this->markets[$this->symbol], true).PHP_EOL;
		// XXX/YYY  BTC/MXN  BTC/USDT
		// Prefer to operate in YYY (market currency), if $market doesnt have the information, fall baco to XXX (quote currency)
		//echo '$this->markets['.$this->symbol.']: '.print_r($this->markets[$this->symbol], true).PHP_EOL;

		// Offline technique
		$minimum_transaction_yyy = floatval($this->markets[$this->symbol]['limits']['amount']['min'])*1.05;   // market currency, 5% because rounding may lower it
		$mm = 'xxx'; $sell_fn = 'sell_yyy'; $buy_fn = 'buy_yyy'; $minimum_transaction = $minimum_transaction_yyy;
		if ($minimum_transaction_yyy == 0){
			$minimum_transaction = 0.0001;
		}

		if (!is_array($this->global_state) || (count($this->global_state) == 0)){
			$this->global_state = $this->global_state();
		}

		try{
			$ceil_price = max($this->last_ticker['bid'], $signal['high_band']);
//echo '$ceil_price:'.$ceil_price.PHP_EOL;
			$_amount_to_sell = $this->has_a_buy_to_compensate($ceil_price);	// in quote currency XXX
//echo '$_amount_to_sell: '.$_amount_to_sell.PHP_EOL;
			$_amount_to_sell = $this->adjust_amount_xxx($_amount_to_sell, 'sell', $ceil_price);
//			$_amount_to_sell2 = $this->convert_balance('sell', $_amount_to_sell);
//echo '2 $_amount_to_sell: '.$_amount_to_sell.PHP_EOL;
//echo '2 $_amount_to_sell2: '.$_amount_to_sell2.PHP_EOL;
			if (!$this->enough_balance_xxx($_amount_to_sell)){
				$_logline = 'Not enough balace to create an offline selling order.';
				$this->log_warning($_logline);
			}
			elseif ($_amount_to_sell > 0){
				// Create an offline selling order

				$_logline = 'Creating offline ceil order '.$_amount_to_sell.($mm=='yyy'?'x':'@').floatval($ceil_price);
				$this->log_notice($_logline);

				$new_order_sell = $this->$sell_fn($_amount_to_sell, $ceil_price);
//				print_r($new_order_sell);
			}
		}
		catch (\ccxt\AuthenticationError $e) {
			$this->log_error($e->getMessage().PHP_EOL);
		}
		catch (\ccxt\NetworkError $e) {
			$this->log_error($e->getMessage().PHP_EOL);
		}
		catch (\ccxt\ExchangeError $e) {
			$exit_code = false;
			$this->log_error($e->getMessage().PHP_EOL);
		}
		catch(Exception $e) {
			$this->log_error($e->getMessage().PHP_EOL);
		}

		if ($sell_only){
			$_logline = 'Can not create an offline BUY order for '.$this->symbol.', sell_only flag is true.';
			$this->log_warning($_logline);
		}
		else{
			// XXX/YYY  BTC/MXN  BTC/USDT
			try{
//echo 'IN $minimum_transaction: '.$minimum_transaction.PHP_EOL;
				$_amount_to_buy = $this->adjust_amount_xxx($minimum_transaction, 'buy', floatval($signal['low_band']));
//				$_amount_to_buy2 = $this->convert_balance('buy', $minimum_transaction);
//echo 'OUT $_amount_to_buy: '.$_amount_to_buy.PHP_EOL;
//echo 'OUT $_amount_to_buy2: '.$_amount_to_buy2.PHP_EOL;
//convert $_amount_to_buy from XXX to YYY and verify we have enough YYY
				$_amount_to_buy_yyy = $this->to_yyy($_amount_to_buy);
				if (!$this->enough_balance_yyy($_amount_to_buy_yyy)){
					$_logline = 'Not enough balace to create an offline buying order.';
					$this->log_warning($_logline);
				}
				elseif ($_amount_to_buy > 0){
					$_logline = 'Creating offline floor order '.$minimum_transaction.($mm=='yyy'?'x':'@').floatval($signal['low_band']);
					$this->log_notice($_logline);

					$new_order_buy = $this->$buy_fn($_amount_to_buy, floatval($signal['low_band']));
//					print_r($new_order_buy);
				}
			}
			catch (\ccxt\AuthenticationError $e) {
				$this->log_error($e->getMessage().PHP_EOL);
			}
			catch (\ccxt\NetworkError $e) {
				$this->log_error($e->getMessage().PHP_EOL);
			}
			catch (\ccxt\ExchangeError $e) {
				$exit_code = false;
				$this->log_error($e->getMessage().PHP_EOL);
			}
			catch(Exception $e) {
				$this->log_error($e->getMessage().PHP_EOL);
			}
		}
	}

	private function report(){
		if (count($this->trading_summary) > 0){
			$this->average_transaction = array_values($this->trading_summary)[0]['average_rate'];
			$current_state = array_values($this->trading_summary)[0]['side'];
		}
		else{
			$this->average_transaction = 0;
			$current_state = 'hodl';
		}
		$_logline  = PHP_TAB.'Current market state:'.PHP_EOL;
		$_logline .= PHP_TAB.PHP_TAB.$this->last_ticker['ask'].' '.$this->symbol.' (selling price - they sell/I buy)'.PHP_EOL;
		$_logline .= PHP_TAB.PHP_TAB.$this->last_ticker['bid'].' '.$this->symbol.' (buying price - they buy/I sell)'.PHP_EOL;
		$_logline .= PHP_TAB.'Current machine state: '.$current_state.PHP_EOL;
		$_logline .= PHP_TAB.'Average transaction: '.$this->average_transaction.' '.$this->symbol;
		$this->log_notice($_logline);
	}

	public function get_my_deposits(?int $since): array{
		if (!$this->exchange->has['fetchDeposits']) {
			throw new Exception ($this->exchange->id . ' does not have the fetch_deposits method');
			return [];
		}

		$_query = false;

		if (is_null($this->my_deposits_last_timestamp)){
			$_query = true;
		}
		else{
			$_diff = time() - $this->my_deposits_last_timestamp;
			if($_diff > 60){
				$_query = true;
			}
		}

		if ($_query){
			$all_deposits = array(); $params['sort'] = 'asc'; $my_deposits = array();
			while ($since < $this->exchange->milliseconds()){
				try{
					// TODO: add try/catch
					$my_deposits = $this->exchange->fetch_deposits($this->base_currency, $since, null, $params);	// index 0 is the first trade
					$this->my_deposits_last_timestamp = time();
					if (count($my_deposits)){
						$selected_deposit = array();
						$first_deposit = reset($my_deposits);
						$last_deposit = end($my_deposits);
						if ($first_deposit['timestamp'] > $last_deposit['timestamp']){
							$selected_deposit = $first_deposit;
						}
						else{
							$selected_deposit = $last_deposit;
						}
						$since = $selected_deposit['timestamp'] + 1;
						$all_deposits = array_merge($all_deposits, $my_deposits);
						$params['marker'] = $selected_deposit['id'];
					}
					else{
						break;
					}
				}
				catch (\ccxt\AuthenticationError $e) {
					// handle authentication error here
					$this->log_error($e->getMessage().PHP_EOL);
				}
				catch (\ccxt\NetworkError $e) {
					// your code to handle the network code and retries here
					$this->log_error($e->getMessage().PHP_EOL);
				}
				catch (\ccxt\ExchangeError $e) {
					// your code to handle an exchange error
					$this->log_error($e->getMessage().PHP_EOL);
				}
				catch(Exception $e) {
					$this->log_error($e->getMessage().PHP_EOL);
				}
			}

			$_logline = __FILE__.':'.__LINE__.' $all_deposits = '.print_r($all_deposits, true);
			$this->log_debug($_logline);

			usort($all_deposits, function ($item1, $item2) { return $item1['timestamp'] <=> $item2['timestamp'];});
			$this->my_deposits = $all_deposits;
		}
		return $this->my_deposits;
	}


	private function get_my_trades(?int $since): array{
		global $debug;

		if (!$this->exchange->has['fetchMyTrades']) {
			throw new Exception ($this->exchange->id . ' does not have the fetch_my_trades method');
			return [];
		}

		$_query = false;

		if (is_null($this->my_trades_last_timestamp)){
			$_query = true;
		}
		else{
			$_diff = time() - $this->my_trades_last_timestamp;
			if($_diff > 60){
				$_query = true;
			}
		}

		if ($_query){
			$all_trades = array(); $params['sort'] = 'asc'; $my_trades = array();
			while ($since < $this->exchange->milliseconds()){
				try{
					// TODO: add try/catch
					$my_trades = $this->exchange->fetch_my_trades($this->symbol, $since, null, $params);	// index 0 is the first trade
					$this->my_trades_last_timestamp = time();
					if (count($my_trades)){
						$selected_trade = array();
						$first_trade = reset($my_trades);
						$last_trade = end($my_trades);
						if ($first_trade['timestamp'] > $last_trade['timestamp']){
							$selected_trade = $first_trade;
						}
						else{
							$selected_trade = $last_trade;
						}
						$since = $selected_trade['timestamp'] + 1;
						$all_trades = array_merge($all_trades, $my_trades);
						$params['marker'] = $selected_trade['id'];
					}
					else{
						break;
					}
				}
				catch (\ccxt\AuthenticationError $e) {
					// handle authentication error here
					$this->log_error($e->getMessage().PHP_EOL);
				}
				catch (\ccxt\NetworkError $e) {
					// your code to handle the network code and retries here
					$this->log_error($e->getMessage().PHP_EOL);
				}
				catch (\ccxt\ExchangeError $e) {
					// your code to handle an exchange error
					$this->log_error($e->getMessage().PHP_EOL);
				}
				catch(Exception $e) {
					$this->log_error($e->getMessage().PHP_EOL);
				}
			}

			$_logline = __FILE__.':'.__LINE__.' $all_trades = '.print_r($all_trades, true);
			$this->log_debug($_logline);

			usort($all_trades, function ($item1, $item2) { return $item1['timestamp'] <=> $item2['timestamp'];});
			$this->my_trades = $all_trades;
		}
		return $this->my_trades;
	}

	// Functions captures all the trades into the db for future analysis
	public function migrate_to_db(?int $since){
		global $debug;

		if (is_null($this->db) || empty($this->db)){
			throw new \Exception('DB handler not set.');
		}

		// TODO: review if this is needed
		if (is_null($since)){
			$since2 = time() - (365 * 86400);
			$since2 *= 1000;				// miliseconds
		}
		else{
			$since2 = $since;
		}

		$all_trades = $this->get_my_trades($since);
		$_exchange = $this->exchange_name; $_symbol = $this->symbol;
		foreach ($all_trades as $trade){
			$_id = $trade['id'];
			$_timestamp = $trade['timestamp'];
			$_side = $trade['side'];
			$_taker_or_maker = $trade['takerOrMaker'];
			$_price = $trade['price'];	// quote currency XXX/YYY
			$_amount = $trade['amount'];	// base currency YYY
			$_cost = $trade['cost'];	// quote currency XXX  = price * amount

			$sql_verify = "SELECT COUNT(*) AS C FROM my_trades WHERE timestamp = :timestamp AND exchange = :exchange AND symbol = :symbol AND id = :id;";
			$statement = $this->db->prepare($sql_verify);
			$statement->bindValue(':timestamp', $_timestamp, SQLITE3_INTEGER);
			$statement->bindValue(':exchange', $_exchange, SQLITE3_TEXT);
			$statement->bindValue(':symbol', $_symbol, SQLITE3_TEXT);
			$statement->bindValue(':id', $_id, SQLITE3_TEXT);
			$result = $statement->execute();
			$row = $result->fetchArray(SQLITE3_ASSOC);

			if ($row['C'] == 0){
				$sql_insert = "INSERT INTO my_trades (id, timestamp, exchange, symbol, side, takerOrMaker, price, amount, cost, missing) 
					VALUES ('$_id', $_timestamp, '$_exchange', '$_symbol', '$_side', '$_taker_or_maker', $_price, $_amount, $_cost, $_amount)";
				try{
					$this->db->exec($sql_insert);
				}
				catch(Exception $e) {
					$this->log_error($e->getMessage().PHP_EOL);
				}
			}
		}

//		$this->get_my_deposits($since2);
//		print_r($this->my_deposits);
	}

	public function find_fullfillments(){
		$config = $this->config->get_data();
		$exit_code = false; $_logline = '';
 		$trader_fee = floatval($this->markets[$this->symbol]['taker']) + floatval($this->markets[$this->symbol]['maker']);				// A sell and a bought
		$min_profit_config = floatval($this->config->safe_value('minimun_profit_percentage', 1))/100;
		$min_profit = 1 + max($trader_fee, $min_profit_config);
		$_exchange = $this->exchange_name; $_symbol = $this->symbol;

		// 1. Find unfullfilled sales
		$sql_unfullfilled_sells = "SELECT * FROM my_trades t 
						WHERE t.side = 'sell' AND
							exchange = :exchange AND
							symbol = :symbol AND
							t.missing > 0 AND
							NOT EXISTS (
								SELECT 1 FROM buys_vs_sells bs 
									WHERE bs.sell_id = t.id
							);";
		$statement = $this->db->prepare($sql_unfullfilled_sells);
		$statement->bindValue(':exchange', $_exchange, SQLITE3_TEXT);
		$statement->bindValue(':symbol', $_symbol, SQLITE3_TEXT);

		try{
			$_query = $statement->getSQL(false);
		}
		catch(Exception $e) {
			$_query = $statement->getSQL(false);
		}

		$_logline = __FILE__.':'.__LINE__.' '.$_query;
		$this->log_debug($_logline);

		$result = $statement->execute();
		while($row = $result->fetchArray(SQLITE3_ASSOC)){

			$_logline = __FILE__.':'.__LINE__.' '.implode(',',$row);
			$this->log_debug($_logline);

			$_ids = $row['id'];
			$_price = $row['price'];
			$_timestamp = $row['timestamp'];
			$_amount_to_buy = $row['amount'];
			$_missing_sell = $row['missing'];
			// 2. Find all elegible purchases 
			$select_unfullfilled_buys = "SELECT * FROM my_trades t 
				WHERE t.side = 'buy' AND
				exchange = :exchange AND
				symbol = :symbol AND
				timestamp < :timestamp AND
				t.missing > 0 AND
				NOT EXISTS (SELECT 1 FROM buys_vs_sells bs WHERE bs.buy_id = t.id) AND
				(t.price <= :price / $min_profit)";
			$statement2 = $this->db->prepare($select_unfullfilled_buys);
			$statement2->bindValue(':exchange', $_exchange, SQLITE3_TEXT);
			$statement2->bindValue(':symbol', $_symbol, SQLITE3_TEXT);
			$statement2->bindValue(':timestamp', $_timestamp, SQLITE3_INTEGER);
			$statement2->bindValue(':price', $_price, SQLITE3_FLOAT);
			try{
				$_query = $statement2->getSQL(false);
			}
			catch(Exception $e) {
				$_query = $statement2->getSQL(false);
			}

			$_logline = __FILE__.':'.__LINE__.' '.$_query;
			$this->log_debug($_logline);

			$result2 = $statement2->execute();
			while($row2 = $result2->fetchArray(SQLITE3_ASSOC)){

				$_logline = __FILE__.':'.__LINE__.' '.implode(',',$row2);
				$this->log_debug($_logline);

				$_idb = $row2['id'];
				$_missing_buy = $row2['missing'];

				// 3. How much have been allocate
				// select bs.sell_id, sum(amount) from buys_vs_sells bs inner join my_trades t on bs.buy_id = t.id where sell_id = 0 group by bs.sell_id;
				$sql_total_sold = 'SELECT bs.sell_id, SUM(amount - missing) AS sold FROM buys_vs_sells bs INNER JOIN my_trades t ON bs.buy_id = t.id WHERE sell_id = :sell_id GROUP BY bs.sell_id;';
				$statement3 = $this->db->prepare($sql_total_sold);
				$statement3->bindValue(':sell_id', $_ids, SQLITE3_TEXT);
				try{
					$_query = $statement3->getSQL(false);
				}
				catch(Exception $e) {
					$_query = $statement3->getSQL(false);
				}

				$_logline = __FILE__.':'.__LINE__.' '.$_query;
				$this->log_debug($_logline);

				$result3 = $statement3->execute();
				$sold = 0;
				while($row3 = $result3->fetchArray(SQLITE3_ASSOC)){

					$_logline = __FILE__.':'.__LINE__.' '.implode(',',$row3);
					$this->log_debug($_logline);

					$sold += floatval($row3['sold']);
				}
				$not_sold_yet = $_amount_to_buy - $sold;

				// not_sold_yet comes from database SUM
				// _mising_* coms from db field, _missing_sell must be the same as not_sold_yet

//echo PHP_TAB.PHP_TAB.'$sold: '.$sold.PHP_EOL;
//echo PHP_TAB.PHP_TAB.'$not_sold_yet: '.$not_sold_yet.PHP_EOL;
//echo PHP_TAB.PHP_TAB.'$_missing_buy: '.$_missing_buy.PHP_EOL;
//echo PHP_TAB.PHP_TAB.'$_missing_sell: '.$_missing_sell.PHP_EOL;

				if ($not_sold_yet > 0){
					$full_fullfillment = false; $buy_fullfillment = false; $sell_fullfillment = false;
					// if to be sold >= missing current buy, then take all the buy
					if ($not_sold_yet >= $_missing_buy){
						$not_sold_yet -= $_missing_buy;
						$_missing_sell -= $_missing_buy;
						$_missing_buy = 0;
					}
					// take only a partial thing
					elseif ($not_sold_yet < $_missing_buy){
						$_missing_buy -= $not_sold_yet;
						$not_sold_yet = 0;
						$_missing_sell = 0;
					}
//echo PHP_TAB.PHP_TAB.'$not_sold_yet: '.$not_sold_yet.PHP_EOL;
//echo PHP_TAB.PHP_TAB.'$_missing_buy: '.$_missing_buy.PHP_EOL;
//echo PHP_TAB.PHP_TAB.'$_missing_sell: '.$_missing_sell.PHP_EOL;

					$sql_update_bs = "INSERT INTO buys_vs_sells(buy_id, sell_id) VALUES('$_idb', '$_ids');";

					$_logline = __FILE__.':'.__LINE__.' '.$sql_update_bs;
					$this->log_debug($_logline);

					try{
						$this->db->exec($sql_update_bs);
					}
					catch(Exception $e) {
						$this->log_error($e->getMessage().PHP_EOL);
					}

					$sql_update_b = "UPDATE my_trades SET missing = $_missing_buy WHERE exchange = '$_exchange' AND symbol = '$_symbol' AND id = '$_idb' AND side='buy';";

					$_logline = __FILE__.':'.__LINE__.' '.$sql_update_b;
					$this->log_debug($_logline);

					try{
						$this->db->exec($sql_update_b);
					}
					catch(Exception $e) {
						$this->log_error($e->getMessage());
					}

					$sql_update_s = "UPDATE my_trades SET missing = $_missing_sell WHERE exchange = '$_exchange' AND symbol = '$_symbol' AND id = '$_ids' AND side='sell';";

					$_logline = __FILE__.':'.__LINE__.' '.$sql_update_s;
					$this->log_debug($_logline);

					try{
						$this->db->exec($sql_update_s);
					}
					catch(Exception $e) {
						$this->log_error($e->getMessage());
					}
				}
			}
		}
	}

	// Asks if you can sell at a given price, answers how much to sell (zero if nothing)
	public function has_a_buy_to_compensate(float $_price): float{
		$config = $this->config->get_data();
		$exit_code = false; $_logline = '';
		$trader_fee = floatval($this->markets[$this->symbol]['taker']) + floatval($this->markets[$this->symbol]['maker']);				// A sell and a bought

		$minimun_profit_percentage = $this->config->safe_value('minimun_profit_percentage', 1);
		$minimun_profit_percentage2 = $this->minimun_profit_percentage();
		$this->log_info('Suggested minimum profit percentage: '.$minimun_profit_percentage2);
		if ($minimun_profit_percentage == 'auto'){
			$minimun_profit_percentage = $minimun_profit_percentage2;
		}
		elseif ($minimun_profit_percentage < $minimun_profit_percentage2){
			$this->log_warning('Minimum suggested percentage profit is '.$minimun_profit_percentage2.'%, your current minimum profit is set to '.$minimun_profit_percentage.'%, you may want to adjust or set it to auto.');
		}

		$min_profit_config = floatval($minimun_profit_percentage)/100;
		$min_profit = 1 + max($trader_fee, $min_profit_config);
		$amount = 0.0;

		$_exchange = $this->exchange_name; $_symbol = $this->symbol;
		$_timestamp = time() * 1000;
		// 1. Find the sum o all elegible purchases 
		$select_unfullfilled_buys = "SELECT SUM(missing) AS amount FROM (SELECT * FROM my_trades t 
			WHERE t.side = 'buy' AND
			exchange = :exchange AND
			symbol = :symbol AND
			timestamp < :timestamp AND
			t.missing > 0 AND
			NOT EXISTS (SELECT 1 FROM buys_vs_sells bs WHERE bs.buy_id = t.id) AND
			(t.price <= :price / $min_profit))";
		$statement2 = $this->db->prepare($select_unfullfilled_buys);
		$statement2->bindValue(':exchange', $_exchange, SQLITE3_TEXT);
		$statement2->bindValue(':symbol', $_symbol, SQLITE3_TEXT);
		$statement2->bindValue(':timestamp', $_timestamp, SQLITE3_INTEGER);
		$statement2->bindValue(':price', $_price, SQLITE3_FLOAT);
		try{
			$_query = $statement2->getSQL(false);
		}
		catch(Exception $e) {
			$_query = $statement2->getSQL(false);
		}

		$_logline = __FILE__.':'.__LINE__.' '.$_query."; timestamp: $_timestamp, price: $_price";
		$this->log_debug($_logline);

		$result2 = $statement2->execute();
		while($row2 = $result2->fetchArray(SQLITE3_ASSOC)){

			$_logline = __FILE__.':'.__LINE__.' '.implode(',',$row2);
			$this->log_debug($_logline);

			$amount += floatval($row2['amount']);
		}

		return $amount;
	}

	public function enough_balance_xxx(float $amount):bool{
		$answer = false;
		if (!is_array($this->balance) || (count($this->balance)) == 0){
			$this->balance = $this->fetch_balance();

			$_logline = __FILE__.':'.__LINE__.' $this->balance: '.print_r($this->balance, true);
			$this->log_debug($_logline);
		}

		if (floatval($amount) <= floatval($this->balance[$this->base_currency]['free'])){
			// Enough balance
			$answer = true;
		}

		return $answer;
	}

	public function enough_balance_yyy(float $amount):bool{
		$answer = false;
		if (!is_array($this->balance) || (count($this->balance)) == 0){
			$this->balance = $this->fetch_balance();

			$_logline = __FILE__.':'.__LINE__.' $this->balance: '.print_r($this->balance, true);
			$this->log_debug($_logline);
		}

		if (floatval($amount) <= floatval($this->balance[$this->market_currency]['free'])){
			// Enough balance
			$answer = true;
		}

		return $answer;
	}

	public function adjust_amount_xxx(float $amount, string $side, ?float $transaction_price):float{
//echo "adjust_amount_xxx(float $amount, string $side, ?float $transaction_price)".PHP_EOL;
		if ($amount > 0){
			// These are the minimum transaction amounts XXX/YYY
			$minimum_transaction_xxx = floatval($this->markets[$this->symbol]['limits']['amount']['min'])*1.05;   // market currency, 5% because rounding may lower it
			$minimum_transaction_yyy = (in_array($this->base_currency,\OKayInc\Trademinator::$stable_coins))?1:0.0001;  // TODO find a better way

			if (isset($this->markets[$this->symbol]['limits']['cost']['min'])){
				$minimum_transaction_yyy = floatval($this->markets[$this->symbol]['limits']['cost']['min']) * 1.05;               // base currency, 5% because rounding may lower it
//echo 'got min from exchange: '.$minimum_transaction_yyy.PHP_EOL;
			}

			$minimum_transaction_yyy_to_xxx = $this->to_xxx($minimum_transaction_yyy);
			$minimum_transaction_xxx_to_yyy = $this->to_yyy($minimum_transaction_xxx);
//echo '$minimum_transaction_xxx: '.$minimum_transaction_xxx.PHP_EOL;
//echo '$minimum_transaction_yyy: '.$minimum_transaction_yyy.PHP_EOL;
//echo 'minimum_transaction_xxx_to_yyy: '.$minimum_transaction_xxx_to_yyy.PHP_EOL;
//echo 'minimum_transaction_yyy_to_xxx: '.$minimum_transaction_yyy_to_xxx.PHP_EOL;

			if ($amount < $minimum_transaction_xxx){
//echo '$amount < $minimum_transaction_xxx'.PHP_EOL;
				$amount = $minimum_transaction_xxx;
//echo 'ADJ 1 $amount: '.$amount.PHP_EOL;
			}

			if ($amount < $minimum_transaction_yyy_to_xxx){
				$amount = $minimum_transaction_yyy_to_xxx;
			}

			$_logline = __FILE__.':'.__LINE__.' $minimum_transaction_xxx = '.$minimum_transaction_xxx.'; $minimum_transaction_yyy = '.$minimum_transaction_yyy;
			$this->log_debug($_logline);

			if (is_null($transaction_price) || ($transaction_price == 0)){
				$amount_yyy_sell = $amount / $this->last_ticker['bid'];
				$amount_yyy_buy = $amount / $this->last_ticker['ask'];
			}
			else{
				$amount_yyy_sell = $amount / $transaction_price;
				$amount_yyy_buy = $amount / $transaction_price;
			}
//echo '$amount_yyy_sell: '.$amount_yyy_sell.PHP_EOL;
//echo '$amount_yyy_buy: '.$amount_yyy_buy.PHP_EOL;
			$amount_yyy = $side=='sell'?$amount_yyy_sell:$amount_yyy_buy;

			// Adjust if XXX minimum transaction is lower than specified
			if (($minimum_transaction_yyy > 0) && ($amount_yyy < $minimum_transaction_yyy)){
				$amount_yyy = $minimum_transaction_yyy;
				$amount_xxx = max($amount_yyy, $minimum_transaction_yyy) / $this->last_ticker[$side=='sell'?'bid':'ask'];
				$amount = max($amount, $amount_xxx);
//echo 'ADJ 2 $amount: '.$amount.PHP_EOL;
			}

			if (($minimum_transaction_yyy > 0) && ($amount_yyy < $minimum_transaction_xxx_to_yyy)){
				$amount_yyy = $minimum_transaction_xxx_to_yyy;
				$amount_xxx = max($amount_yyy, $minimum_transaction_xxx_to_yyy) / $this->last_ticker[$side=='sell'?'bid':'ask'];
				$amount = max($amount, $amount_xxx);
//echo 'ADJ 2a $amount: '.$amount.PHP_EOL;
			}

			switch ($side){
				case 'buy':
//echo 'YYY balance: '.$this->balance[$this->market_currency]['free'].PHP_EOL;
					if ($amount_yyy > $this->balance[$this->market_currency]['free']){
						$amount = 0;
					}
					break;
				case 'sell':
					if ($amount > $this->balance[$this->base_currency]['free']){
						$amount = 0;
					}
				break;
			}
		}
//echo 'FINAL $amount: '.$amount.PHP_EOL;

		return $amount;
	}

	// returns the XXX that can not be sold because of lacking a recorded purchase
	// returns the ceil price (profit included) that that XXX balance should be sold, could be zero if no purchase is registered
	public function find_excess(): array{
		if ((!is_array($this->balance)) || (count($this->balance) == 0)){
			$this->balance = $this->fetch_balance();
		}
		$config = $this->config->get_data();
		$trader_fee = floatval($this->markets[$this->symbol]['taker']) + floatval($this->markets[$this->symbol]['maker']);				// A sell and a bought	50% => 0.50, 0.20% => 0.0020
		$min_profit_config = floatval($this->config->safe_value('minimun_profit_percentage', 1))/100;	// 1% => 0.01
		$minimum_profit = 1 + max($trader_fee, $min_profit_config);

		$allocated_xxx = 0; $price_xxx = 0;

		$_exchange = $this->exchange_name; $_symbol = $this->symbol;
		$sql_find = 'SELECT * FROM my_trades WHERE exchange = :exchange AND symbol = :symbol AND missing > 0 ORDER BY amount DESC LIMIT 1';
		$statement = $this->db->prepare($sql_find);
		$statement->bindValue(':exchange', $_exchange, SQLITE3_TEXT);
		$statement->bindValue(':symbol', $_symbol, SQLITE3_TEXT);
		try{
			$_query = $statement->getSQL(false);
		}
		catch(Exception $e) {
			$_query = $statement->getSQL(false);
		}

		$_logline = __FILE__.':'.__LINE__.' '.$_query;
		$this->log_debug($_logline);

		$result = $statement->execute();
		while($row = $result->fetchArray(SQLITE3_ASSOC)){
			$price_xxx = $row['price'];
		}

		$price_xxx *= $minimum_profit;
		if ($price_xxx > 0){
			// There is a record
			$allocated_xxx = $this->has_a_buy_to_compensate($price_xxx);
		}
		$unallocated_xxx = $this->balance[$this->base_currency]['total'] - $allocated_xxx;

		return [$unallocated_xxx, $price_xxx];
	}

	public function general_profit_report(bool $show_details = false){
		$_exchange = $this->exchange_name; $_symbol = $this->symbol;
		$sql_fullfilled_sells  = 'SELECT * FROM my_trades WHERE exchange = :exchange AND symbol = :symbol AND missing = 0 AND side = "sell" ORDER BY timestamp DESC;';
		$statement = $this->db->prepare($sql_fullfilled_sells);
		$statement->bindValue(':exchange', $_exchange, SQLITE3_TEXT);
		$statement->bindValue(':symbol', $_symbol, SQLITE3_TEXT);
		try{
			$_query = $statement->getSQL(false);
		}
		catch(Exception $e) {
			$_query = $statement->getSQL(false);
		}

		$_logline = __FILE__.':'.__LINE__.' '.$_query;
		$this->log_debug($_logline);

		$i = 0; $transactions = array();
		$result = $statement->execute();
		while($row = $result->fetchArray(SQLITE3_ASSOC)){
			$_sell_id = $row['id'];
			$transactions[$i]['recovered'] = $row['cost'];	// YYY Market currency
			// amount & missing are in XXX currency, results need to be in YYY
			$sql_find_buys = 'SELECT (b.amount - b.missing) * b.price AS invested FROM buys_vs_sells bs INNER JOIN my_trades b ON b.id = bs.buy_id WHERE bs.sell_id = :sell_id AND b.side = "buy"';
			$statement2 = $this->db->prepare($sql_find_buys);
			$statement2->bindValue(':sell_id', $_sell_id, SQLITE3_TEXT);
			try{
					$_query = $statement2->getSQL(false);
			}
			catch(Exception $e) {
				$_query = $statement->getSQL(false);
			}

			$_logline = __FILE__.':'.__LINE__.' '.$_query;
			$this->log_debug($_logline);

			$j = 0;
			$result2 = $statement2->execute();
			while($row2 = $result2->fetchArray(SQLITE3_ASSOC)){
				$transactions[$i]['invests'][$j] = $row2['invested'];
				$j++;
			}
			$i++;
		}

		$t_i = 0; $t_r = 0;
		$headers = sprintf("%10s %10s %10s %10s\n",'invest', 'recover', 'profit', '%');
		echo PHP_EOL.$this->colour->convert('%w'.$headers);
		foreach ($transactions as &$t){
			$ii = array_sum($t['invests']);
			$t['profit'] = $t['recovered'] - $ii;
			$t['profit_percentage'] = 100 * $t['profit'] / $ii;
			$t_i += $ii;
			$t_r += $t['recovered'];

			if ($show_details){
				$line = sprintf("%10f %10f %10f %10f\n", $ii, $t['recovered'], $t['profit'], $t['profit_percentage']);
				echo $this->colour->convert('%w'.$line);
			}
		}

		$total_profit = $t_r - $t_i;
		if ($t_i > 0){
			$total_profit_percentage = 100 * $total_profit / $t_i;
		}
		else{
			$total_profit_percentage = 0;
		}
		echo '----------------------------------------------'.PHP_EOL;
		$line = sprintf("%10f %10f %10f %10f\n", $t_i, $t_r, $total_profit, $total_profit_percentage);
		echo $this->colour->convert('%W'.$line);
	}


	public function minimun_profit_percentage(){
		$trader_fee = $this->markets[$this->symbol]['taker'];
                $owner_reminding = 1 - $trader_fee;
                $min_profit = bcmul(bcsub(1.0000, bcmul($owner_reminding, $owner_reminding, 8), 8), 100.0, 8);	// Must be in % units
//		$min_profit = 1;
		if (is_array($this->signal)){
			$min_profit = floatval($this->signal['parameters']['minimun_profit_percentage']);
		}

		return $min_profit;
	}

	public function to_yyy(float $amount_xxx): float {
		if (count($this->last_ticker) == 0){
			$this->last_ticker = $this->exchange->fetch_ticker($this->symbol);

			$_logline = __FILE__.':'.__LINE__.' $this->last_ticker: '.print_r($this->last_ticker, true);
			$this->log_debug($_logline);
		}

		$amount_yyy = $amount_xxx * $this->last_ticker['bid'];
		return $amount_yyy;
	}

	public function to_xxx(float $amount_yyy): float {
		if (count($this->last_ticker) == 0){
			$this->last_ticker = $this->exchange->fetch_ticker($this->symbol);

			$_logline = __FILE__.':'.__LINE__.' $this->last_ticker: '.print_r($this->last_ticker, true);
			$this->log_debug($_logline);
		}

		$amount_xxx = $amount_yyy / $this->last_ticker['ask'];
		return $amount_xxx;
	}

	private function adjust_xxx(float $amount_xxx): float{
		$amount = $amount_xxx;
		if ($amount > $this->balance[$this->base_currency]['free']){
			$amount = $this->balance[$this->base_currency]['free'];
		}
		return $amount;
	}

	private function adjust_yyy(float $amount_yyy): float{
		$amount = $amount_yyy;
		if ($amount > $this->balance[$this->market_currency]['free']){
			$amount = $this->balance[$this->market_currency]['free'];
		}
		return $amount;
	}

	private function fetch_balance(?array $params = []): array{
		if (is_null($params)){
			$params = array();
		}

		$result = array();
		$loop = true;
		do {
			$balance = $this->exchange->fetch_balance($params);
			$pagination = $this->exchange->safe_value($balance['info'], 'pagination');
			if ($pagination === null){
				$loop = false;
			}
			else{
				$next_starting_after = $this->exchange->safe_string($pagination, 'next_starting_after');
				if ($next_starting_after !== null){
					$params['starting_after'] = $next_starting_after;
				}
				else{
					$loop = false;
				}
			}
			$result = $this->exchange->deep_extend($result, $balance);
		} while ($loop);

		return ($this->balance = $result);
	}

	private function record_balances(){
		if (is_null($this->balance) || (count($this->balance) == 0)){
			$this->balance = $this->fetch_balance();
		}
		$this->record_balance($this->base_currency);
		$this->record_balance($this->market_currency);
	}

	private function record_balance(string $currency){
		$now = strtotime('now');
		$todays_midnight = strtotime('today midnight');
		$tomorros_midnight = strtotime('tomorrow');
		$sql_verify = "SELECT COUNT(*) AS C FROM balances WHERE exchange = :exchange AND currency = :currency AND timestamp >= $todays_midnight AND timestamp < $tomorros_midnight";
		$statement = $this->db->prepare($sql_verify);
		$statement->bindValue(':exchange', $this->exchange_name, SQLITE3_TEXT);
		$statement->bindValue(':currency', $currency, SQLITE3_TEXT);
		$result = $statement->execute();
		$row = $result->fetchArray(SQLITE3_ASSOC);

		if ($row['C'] == 0){
			$sql_insert = "INSERT INTO balances (timestamp, exchange, currency, balance) 
				VALUES ($now, '".$this->exchange_name."', '$currency', ".$this->balance[$currency]['total'].")";
			try{
				$this->db->exec($sql_insert);
			}
			catch(Exception $e) {
				$this->log_error($e->getMessage().PHP_EOL);
			}
		}
	}
}
