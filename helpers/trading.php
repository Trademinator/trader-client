<?php
namespace okayinc\trademinator\trading;

function octobot_signal($exchange, $symbol, $type, $side, $amount = null, $price = null){
	// curl -v  -X POST -H 'Content-Type: text/plain; charset=utf-8'  -d 'TOKEN=MYHASH
	//EXCHANGE=gateio
	//SYMBOL=XMRUSDT
	//SIGNAL=BUY
	//ORDER_TYPE=MARKET' http://192.168.7.108:9001/webhook/trading_view
	global $config, $debug, $dry, $since, $limit;
	if ($debug){
		echo '>>> '.__FUNCTION__."(exchange, $symbol, $type, $side, $amount = null, $price = null)".PHP_EOL;
	}
	$side = strtoupper($side);
	$exchange_name = $exchange->id;
	list($c, $m) = explode('/', urldecode($symbol));
	$payload = '';
	if (!is_null($config[$config['mode']]['token'])){
		$payload = 'TOKEN='.$config[$config['mode']]['token'].PHP_EOL;
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
	$url = $config[$config['mode']]['url'];
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_USERAGENT, 'Trademinator Client 1.0');
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

	if (($exchange->has['fetchMyTrades']) && (intval($config['trademinator']['minimum_operation_space_in_seconds']) > 0)) {
		$my_trades = $exchange->fetch_my_trades($symbol, $since, $limit, []);
		$last_trade = end($my_trades);
		if ($last_trade != false){
			$time_passed = (time() * 1000) - $last_trade['timestamp'];
			if ($time_passed < $config['trademinator']['minimum_operation_space_in_seconds']){
				$dry = true;
			}
		}
	}

	if (!$dry){
		$response = curl_exec($ch);
	}
	if ($response === false){
		echo 'Error: '.curl_errno($ch).':'.curl_error($ch).PHP_EOL;
	}
	else{
		curl_close($ch);
	}

	return $response;
}

function trademinator_signal($exchange, $symbol, $type, $side, $amount = null, $price = null){
	global $config, $debug, $dry, $since, $limit;
	$answer = null;
	if ($debug){
		echo '>>> '.__FUNCTION__."(exchange, $symbol, $type, $side, $amount = null, $price = null)".PHP_EOL;
	}
	if (is_null($amount)){
		if ($debug){
			echo '>>> '.__FUNCTION__.': $amount is null'.PHP_EOL;
		}
		list($c, $m) = explode('/', urldecode($symbol));
		$balance = $exchange->fetch_balance(); $cc = $m;
		if ($side = 'sell'){
			$cc = $c;
		}
		$amount = $balance[$cc]['free'] * $config['trademinator']['risk_percentage'] / 100;	// TODO create a dynamic amount based on risk
		if ($debug){
			echo '>>> '.__FUNCTION__.': '.$cc.' available balance = '.$balance[$cc]['free'].PHP_EOL;
			echo '>>> '.__FUNCTION__.': '.$cc.' calculated balance in this order = '.$amount.PHP_EOL;
		}
	}

	if (($exchange->has['fetchMyTrades']) && (intval($config['trademinator']['minimum_operation_space_in_seconds']) > 0)) {
		$my_trades = $exchange->fetch_my_trades($symbol, $since, $limit, []);
		$last_trade = end($my_trades);
		if ($last_trade != false){
			$time_passed = (time() * 1000) - $last_trade['timestamp'];
			if ($time_passed < $config['trademinator']['minimum_operation_space_in_seconds']){
				$dry = true;
			}
		}
	}

	// Check if order $type is supported, otherwise change it
	if (!$dry){
		$answer = $exchange->create_order($symbol, $type, $side, $amount, $price);
	}
	if ($debug){
		echo '>>> '.__FUNCTION__.'() = '.str_replace(PHP_EOL, '', var_export($answer, true)).PHP_EOL;
	}
	return $answer;
}


function trading_summary($exchange, $symbol){
	global $debug;

	if ($debug){
		echo '>>> '.__FUNCTION__."(exchange, $symbol)".PHP_EOL;
	}

	$status_answer = null; $average_rate = null; $total_sells = 0; $total_buys = 0; $global_average_rate = 0; $global_sell_rate = 0; $global_buy_rate = 0;
	try{
		$trades = $exchange->fetch_my_trades($symbol, null, 999);
		if ($debug){
			echo '>>> '.__FUNCTION__.': my $trades: '.print_r($trades, true).PHP_EOL;
		}
		$states = array(); $global_state['average_rate'] = 0;
		if (($last_trade = end($trades)) != false){
			$status_answer = $last_trade['side'];
			$decimals = 6;

			$i = count($trades) - 1; $j = $i; $k = 1;
			do{
				if ($debug){
					echo '>>> '.__FUNCTION__.': last_trade[side] = '.$last_trade['side'] . '; trades['.$i.'][side]  = '.$trades[$i]['side'].PHP_EOL;
				}

				if ($last_trade['side'] != $trades[$i]['side']){
					$j = $i; $k = 1;
				}

				if (!isset($states[$j]['sold'])){
					$states[$j]['sold'] = 0;
				}

				if (!isset($states[$j]['bought'])){
					$states[$j]['bought'] = 0;
				}

				$states[$j]['side'] = $trades[$i]['side'];
				$states[$j]['sold'] += floatval(number_format($trades[$i]['amount'],$decimals,'.',''));
				$states[$j]['bought'] += floatval(number_format($trades[$i]['cost'],$decimals,'.',''));
				$states[$j]['consecutive'] = $k;
//				$states[$j]['bought'] += floatval(number_format($trades[$i]['fee']['cost'],$decimals,'.',''));	// CCXT seems to already include the fee in the cost

				$total_sells += floatval(number_format($trades[$i]['amount'],$decimals,'.',''));
				$total_buys += floatval(number_format($trades[$i]['cost'],$decimals,'.',''));
				$last_trade = $trades[$i]; $k++;

			} while (--$i >=0);


			foreach ($states as &$state){
				$state['average_rate'] = $state['bought'] / $state['sold'];
			}
			$global_average_rate = $total_buys / $total_sells;
			$global_state['average_rate'] = $global_average_rate;
		}
	}
	catch(Exception $e) {
		if ($debug){
			echo '>>> '.__FUNCTION__.$e->getMessage().PHP_EOL;
		}
	}

	// $states has the summary, lets do some statistics
	$max_buying_consecutives = 1; $max_selling_consecutives = 1; $total_buys = 0; $total_sells = 0; $ii = 0; $jj = 0; $sellings_sold = 0; $sellings_bought = 0; $buyings_sold = 0; $buyings_bought = 0;
	$sellings = array(); $buyings = array();
	foreach ($states as $state){
		if ($state['side'] == 'sell'){
			$max_selling_consecutives = max($max_selling_consecutives, $state['consecutive']);
			$sellings[] = $state['consecutive'];
			$sellings_sold += $state['sold'];
			$sellings_bought += $state['bought']; 
			$ii++;
		}
		elseif ($state['side'] == 'buy'){
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

	return array($states, $global_state);
}

// symbol = XXX/YYY
function calculate_amount($exchange, $symbol, $side, &$last_ticker, &$trading_summary, &$balance){
	global $debug;

	if ($debug){
		echo '>>> '.__FUNCTION__."(exchange, $symbol, $side, last_ticker, trading_summary, balance)".PHP_EOL;
	}
	$amount = null;
	if (count($trading_summary) == 0){
		if ($debug){
			echo '>>> '.__FUNCTION__.': count($trading_sumary) == 0'.PHP_EOL;
		}
		list($trading_summary, $global_state) = \okayinc\trademinator\trading\trading_summary($exchange, $symbol);
	}

	if (count($last_ticker) == 0){
		if ($debug){
			echo '>>> '.__FUNCTION__.': count($last_ticker) == 0'.PHP_EOL;
		}
		$last_ticker = $exchange->fetch_ticker($symbol);
	}

	if (count($balance) == 0){
		if ($debug){
			echo '>>> '.__FUNCTION__.': count($balance) == 0'.PHP_EOL;
		}
		$balance = $exchange->fetch_balance();
	}

	list($c, $m) = explode('/', urldecode($symbol));
	$cc = $c; $switch_action = false;
	if ((count($trading_summary) > 0) && ($last_trading = array_values($trading_summary)[0]) != false){
		// Calculate based on the displacement

		if ($last_trading['side'] == $side){
			if ($debug){
				echo '>>> '.__FUNCTION__.': same action '.$side.PHP_EOL;
			}
			// Buy or sell more
			// ask  -       others selling, me buying
			// bid  -       others buying, me selling
			if ($last_trading['side'] == 'sell'){
				$difference = $last_ticker['bid'] - $last_trading['average_rate'];
				if ($debug){
					echo '>>> '.__FUNCTION__.': $difference = $last_ticker[bid]('.$last_ticker['bid'].') - $last_trading[average_rate]('.$last_trading['average_rate'].') = '.$difference.PHP_EOL;
				}
				if ($difference < 0){
					$difference = 0;
					if ($debug){
						echo '>>> '.__FUNCTION__.': $difference('.$difference.') < 0  => $difference = '.$difference.PHP_EOL;
					}
				}
			}
			else{
				$difference = $last_ticker['ask'] - $last_trading['average_rate'];
				$cc = $m;
				if ($debug){
					echo '>>> '.__FUNCTION__.': $difference = $last_ticker[ask]('.$last_ticker['ask'].') - $last_trading[average_rate]('.$last_trading['average_rate'].') = '.$difference.PHP_EOL;
				}
				if ($difference > 0){
					// Price has increased, it is not a business if we buy at a higher price
					$difference = 0;
					if ($debug){
						echo '>>> '.__FUNCTION__.': $difference('.$difference.') > 0  => $difference = '.$difference.PHP_EOL;
					}
				}
				$difference = abs($difference);
			}
		}
		else{
			if ($debug){
				echo '>>> '.__FUNCTION__.': switching action to '.$side.PHP_EOL;
			}
			$switch_action = true;
			// Side changed
			if ($last_trading['side'] == 'sell'){
				// I sold, now I buy
				$difference = abs($last_ticker['ask'] - $last_trading['average_rate']);
				$cc = $m;
				if ($debug){
					echo '>>> '.__FUNCTION__.': $difference = abs($last_ticker[ask]('.$last_ticker['ask'].') - $last_trading[average_rate]('.$last_trading['average_rate'].')) = '.$difference.PHP_EOL;
				}
			}
			else{
				// I bought, now I sell
				$difference = abs($last_ticker['bid'] - $last_trading['average_rate']);
				if ($debug){
					echo '>>> '.__FUNCTION__.': $difference = abs($last_ticker[bid]('.$last_ticker['bid'].') - $last_trading[average_rate]('.$last_trading['average_rate'].')) = '.$difference.PHP_EOL;
				}
				if ($difference < 0){
					// Price has decreased, it is not a business if we sell cheaper	// TODO: review this logic
					$difference = 0;
					if ($debug){
						echo '>>> '.__FUNCTION__.': $difference('.$difference.') < 0  => $difference = '.$difference.PHP_EOL;
					}
				}
			}
		}

		$consecutive = $switch_action?1:$last_trading['consecutive'];
		if ($side == 'buy'){
			// We buy conservative
			$percentage = 6 * ($difference / $last_trading['average_rate']) * $consecutive;
			if ($debug){
				echo '>>> '.__FUNCTION__.': $percentage = 10 * ($difference('.$difference.') / $last_trading[average_rate]('.$last_trading['average_rate'].')) * $consecutive('.$consecutive.') = '.$percentage.PHP_EOL;
			}
		}
		else{
			// We sell more agresive
			$percentage = 0.85;
			if ($debug){
				echo '>>> '.__FUNCTION__.': $percentage = '.$percentage.PHP_EOL;
			}
		}

		$amount = $balance[$cc]['free'] * $percentage;	// TODO: find a better way
		if ($debug){
			echo '>>> '.__FUNCTION__.': $amount = $balance['.$cc.'][free]('.$balance[$cc]['free'].') * $percentage('.$percentage.') = '.$amount.PHP_EOL;
		}
	}
	else{
		// There is no previous trading activity, use a fixed formula instead
		if ($side == 'buy'){
			$cc = $m;
		}
		$amount = $balance[$cc]['free'] * 0.10;	// Start with 10%, first iteractions are not always the best
		if ($debug){
			echo '>>> '.__FUNCTION__.': $amount = $balance[$c][free]('.$balance[$c]['free'].') * (0.10) = '.$amount.PHP_EOL;
		}
		$percentage = 0;
	}

	// $amount is in market currency, we haven't verified if the minimum is enough

	if ($debug){
		echo '>>> '.__FUNCTION__.'() = '.$amount.PHP_EOL;
	}
	return $amount;		// Must deliver in Market currency
}

// symbol = XXX/YYY
function convert_balance($exchange, &$balance, $side, $symbol, $amount, $last_ticker, &$global_state){
	global $debug;
	$answer = null;
	list ($base, $market) = explode('/', urldecode($symbol));

	if ($debug){
		echo '>>> '.__FUNCTION__."(exchange, balance, $side, $symbol, $amount, last_ticker, global_state)".PHP_EOL;
	}

	if (count($last_ticker) == 0){
		if ($debug){
			echo '>>> '.__FUNCTION__.': count($last_ticker) == 0'.PHP_EOL;
		}
		$last_ticker = $exchange->fetch_ticker($symbol);
	}

	if (count($balance) == 0){
		if ($debug){
			echo '>>> '.__FUNCTION__.': count($balance) == 0'.PHP_EOL;
		}
		$balance = $exchange->fetch_balance();
	}

	if (count($global_state) == 0){
		if ($debug){
			echo '>>> '.__FUNCTION__.': count($trading_sumary) == 0'.PHP_EOL;
		}
		list($trading_summary, $global_state) = \okayinc\trademinator\trading\trading_summary($exchange, $symbol);
	}

	$requires_price = $exchange->safe_value($exchange->options, 'createMarketBuyOrderRequiresPrice', false);
	if ($debug){
		echo '>>> '.__FUNCTION__.': $requires_price = '.(int)$requires_price.PHP_EOL;
	}

	// The currency speciied in $amount changes depending on $type
	switch ($side){
		case 'buy':
			$amount_yyy = $amount;
			$amount_xxx = $amount_yyy / $last_ticker['ask'];
			break;
		case 'sell':
			$amount_xxx = $amount;
			$amount_yyy = $amount_xxx * $last_ticker['bid'];
			break;
		default:
			$amount_xxx = $amount;
			$amount_yyy = $amount;
	}

	$markets = $exchange->load_markets();
	$minimum_transaction_yyy = floatval($markets[$symbol]['limits']['cost']['min'])*1.10;	// market currency, 10% because rounding may lower it
	$minimum_transaction_xxx = ($base == 'USDT')?1:0.0001;	// TODO find a better way
	if (!is_null($markets[$symbol]['limits']['amount']['min'])){
		$minimum_transaction_xxx = floatval($markets[$symbol]['limits']['amount']['min']) * 1.10;		// base currency, 10% because rounding may lower it
	}

	switch ($side){
		case 'buy':
			// Check if there is enough balance on the $market currency as $amount_yyy is given in $base
			// ask  -       others selling, me buying
			// bid  -       others buying, me selling

			if ($debug){
				echo '>>> '.__FUNCTION__.': YYY balance: '.$balance[$market]['free'].PHP_EOL;
				echo '>>> '.__FUNCTION__.': $amount_yyy = '.$amount_yyy.PHP_EOL;
			}

			// No need to convert, just check balance
			if (floatval($amount_yyy) <= floatval($balance[$market]['free'])){
				if ($debug){
					echo '>>> '.__FUNCTION__.': $amount_yyy('.$amount_yyy.') <= $balance['.$market.'][free]('.$balance[$market]['free'].')'.PHP_EOL;
				}
				// There is enough balance :)

				// Checks if there is no dust left
				$left_yyy = $balance[$market]['free'] - $amount_yyy;
				if ($debug){
					echo '>>> '.__FUNCTION__.': $left_yyy = $balance['.$market.'][free]('.$balance[$market]['free'].') - $amount_yyy('.$amount_yyy.') = '.$left_yyy.PHP_EOL;
				}
				if ($left_yyy < $minimum_transaction_yyy){
					$amount_yyy = $balance[$market]['free'];
					if ($debug){
						echo '>>> '.__FUNCTION__.': We may have dust, adjusting $amount_yyy = $balance['.$market.'][free]('.$balance[$market]['free'].')'.PHP_EOL;
					}
				}
			}
			else{
				// Lets use all we have
				$amount_yyy = $balance[$market]['free'];
				$amount_xxx = $amount_yyy / $last_ticker['ask'];
				if ($debug){
					echo '>>> '.__FUNCTION__.': Not enough YYY balance, lets use all we have $amount_yyy = '.$amount_yyy.PHP_EOL;
				}
			}
			break;
		case 'sell':
			if ($debug){
				echo '>>> '.__FUNCTION__.': XXX balance: '.$balance[$base]['free'].PHP_EOL;
				echo '>>> '.__FUNCTION__.': $amount_xxx = '.$amount_xxx.PHP_EOL;
			}

			if (floatval($amount_xxx) <= floatval($balance[$base]['free'])){
				if ($debug){
					echo '>>> '.__FUNCTION__.': $amount_xxx('.$amount_xxx.') <= $balance['.$base.'][free]('.$balance[$base]['free'].')'.PHP_EOL;
				}
				// There is enough balance :)

				// Checks if there is no dust left
				$left_xxx = $balance[$base]['free'] - $amount_xxx;
				if ($debug){
					echo '>>> '.__FUNCTION__.': $left_xxx = $balance['.$base.'][free]('.$balance[$base]['free'].') - $amount_xxx('.$amount_xxx.') = '.$left_xxx.PHP_EOL;
				}
				if ($left_xxx < $minimum_transaction_xxx){
					$amount_xxx = $balance[$base]['free'];
					if ($debug){
						echo '>>> '.__FUNCTION__.': We may have dust, adjusting $amount_xxx = $balance['.$base.'][free]('.$balance[$base]['free'].')'.PHP_EOL;
					}
				}
			}
			else{
				// Lets use all we have
				$amount_xxx = $balance[$base]['free'];
				$amount_yyy = $amount_xxx * $last_ticker['bid'];
				if ($debug){
					echo '>>> '.__FUNCTION__.': Not enough XXX balance, lets use all we have $amount_xxx = '.$amount_xxx.PHP_EOL;
				}
			}
			break;
		default:
				$answer = $amount_yyy;
	}

	if ($debug){
		echo '>>> '.__FUNCTION__.': $markets['.$symbol.'] = '.print_r($markets[$symbol], true).PHP_EOL;
		echo '>>> '.__FUNCTION__.': $amount_xxx = '.$amount_xxx.PHP_EOL;
		echo '>>> '.__FUNCTION__.': $minimum_transaction_xxx = '.$minimum_transaction_xxx.PHP_EOL;
		echo '>>> '.__FUNCTION__.': $amount_yyy = '.$amount_yyy.PHP_EOL;
		echo '>>> '.__FUNCTION__.': $minimum_transaction_yyy = '.$minimum_transaction_yyy.PHP_EOL;
	}

	if (($minimum_transaction_yyy > 0) && ($amount_yyy < $minimum_transaction_yyy)){
		// We can't operate
		$amount_yyy = $minimum_transaction_yyy;
		$amount_xxx = $amount_yyy / ($side=='buy'?$last_ticker['ask']:$last_ticker['bid']);
		if ($debug){
			echo '>>> '.__FUNCTION__.': $amount_yyy('.$amount_yyy.') < $minimum_transaction_yyy('.$minimum_transaction_yyy.'), adusting $amount_yyy = '.$amount_yyy.' and $amount_xxx = '.$amount_xxx.PHP_EOL;
		}
	}

	if (($minimum_transaction_xxx > 0) && ($amount_xxx < $minimum_transaction_xxx)){
		// We can't operate
		$amount_xxx = $minimum_transaction_xxx;
		$amount_yyy = $amount_xxx * ($side=='buy'?$last_ticker['ask']:$last_ticker['bid']);
		if ($debug){
			echo '>>> '.__FUNCTION__.': $amount_yyy('.$amount_yyy.') < $minimum_transaction_yyy('.$minimum_transaction_yyy.'), adusting $amount_yyy = '.$amount_yyy.' and $amount_xxx = '.$amount_xxx.PHP_EOL;
		}
	}

	// TODO: Veriy
	if ($requires_price){
		$answer = $amount_yyy;
	}
	else{
		$answer = $amount_xxx;	// gateio buying answer is expected in XXX
	}


	if ($debug){
		echo '>>> '.__FUNCTION__.' = '.$answer.PHP_EOL;
	}
	return $answer;
}
