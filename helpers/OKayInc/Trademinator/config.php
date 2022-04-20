<?php
namespace OKayInc\Trademinator;

defined("PHP_TAB") or define("PHP_TAB", "\t");

class Config {
	private array $data;
	const force_lowercase = 1;
	const force_uppercase = 2;
	private ?string $filename;

	function __construct(?string $filename = null){
		// Creates Default Configuration
		$this->filename = $filename;
		$this->data = [
			'mode' => 'trademinator',
			'trademinator' => [
				'url' => 'https://signals.trademinator.com',
				'subscription_email' => null,
				'minimun_profit_percentage' => 1,
				'debug' => false,
				'risk_percentage' => 25,
				'minimum_points' => 1,
				'minimum_non_operation_space_in_seconds' => 300,
				'minimum_operation_space_in_seconds' => 600
			],
			'octobot' => [
				'url' => 'http://127.0.0.1:9001/webhook/trading_view',
				'token' => null
			],
			'exchanges' => [
			],
		];

		if (!is_null($filename) && file_exists($filename)){
			$this->read_config($filename);
		}
	}


	private function ask_string($prompt, int $force = 0, array $options2 = [], ?string $default = null): string {
		$options = array();
		if (is_string($default)){
			$options['options']['default'] = $default;
		}

		do{
			$line = readline($prompt);
			if ((strlen($line) == 0) && (is_string($default))){
				$line = $default;
			}

			switch ($force){
				case self::force_lowercase:
					$line = mb_strtolower($line);
					break;
				case self::force_uppercase:
					$line = mb_strtoupper($line);
					break;
			}

			if (count($options2) > 0){
				$options2 = array_map(function($v){return preg_quote($v,'/');}, $options2);
				$regex = '/'.implode('|', $options2).'/';
			}
			else{
				$regex = '/.*/';
			}
		}while(preg_match($regex, $line) == false);

		return $line;
	}

	private function ask_email($prompt): ?string {
		do{
			$line = readline($prompt);
		} while (!filter_var($line, FILTER_VALIDATE_EMAIL));

		return $line;
	}

	private function ask_int($prompt, ?int $min, ?int $max, ?int $default): int {
		$options = array();
		if (is_int($default)){
			$options['options']['default'] = $default;
		}
		if (is_int($min)){
			$options['options']['min_range'] = $min;
		}
		if (is_int($max)){
			$options['options']['max_range'] = $max;
		}

		do{
			$line = readline($prompt);
			if (strlen($line) == 0 && is_int($default)){
				$line = $default;
			}
		}while (!filter_var($line, FILTER_VALIDATE_INT, $options));

		return $line;
	}

	private function ask_url($prompt, ?string $default): string {
		$options = array();
		if (is_string($default)){
			$options['options']['default'] = $default;
		}
		$options['flags'] = FILTER_FLAG_SCHEME_REQUIRED|FILTER_FLAG_HOST_REQUIRED;
		do{
			$line = readline($prompt);
			if (strlen($line) == 0 && is_string($default)){
				$line = $default;
			}
		}while (!filter_var($line, FILTER_VALIDATE_URL));

		return $line;
	}

	private function ask_bool($prompt, ?bool $default): bool {
		$options = array();
		$options['flags'] = FILTER_NULL_ON_FAILURE;
		if (is_bool($default)){
			$options['options']['default'] = $default;
		}
		do{
			$line = readline($prompt);
			if (strlen($line) == 0 && is_bool($default)){
				$line = $default;
			}
			$answer = filter_var($line, FILTER_VALIDATE_BOOLEAN, $options);		// Bool has a different logic
		}while (is_null($answer));

		return $answer;
	}

	private function read_config(string $filename){
		if (is_readable($filename)){
			$content = file_get_contents($filename);
			$new_data = json_decode($content, true);
			$this->data = array_merge($this->data, $new_data);
			unset($new_data);
		}
		else{
			throw new \Exception('Could not read '.$filename.'.');
		}
	}

	private function write_config(string $filename){
		$payload = json_encode($this->data, JSON_NUMERIC_CHECK|JSON_PRETTY_PRINT);
		if (file_put_contents($filename, $payload) === false){
			throw new \Exception('Could not write '.$filename.'.');
		}
	}

	public function setup(){

		$mode = $this->ask_string('Enter Trademinator working mode {trademinator|octobot} [trademinator]: ', self::force_lowercase, ['trademinator','octobot'], 'trademinator');
		$this->data['mode'] = $mode;

		switch ($mode){
			case 'trademinator':
				$trademinator_subscription_email = $this->ask_email('Enter your Trademinator subscription email (put your personal email if you do not have a subscription): ');
				$trademinator_minimun_profit_percentage = $this->ask_int('[general] Enter the minimum profit transaction percentage [1] (0-100): ', 0, 100, 1);
				$trademinator_risk_percentage = $this->ask_int('[general] Enter the risk percentage [25] (1-100): ', 1, 100, 25);
				$trademinator_minimum_points = $this->ask_int('[general] Enter the minimum acceptable points transaction [1] (1-): ', 1, null, 1);
				$trademinator_minimum_non_operation_space_in_seconds = $this->ask_int('[general] Enter the minimum space in seconds after a non-transaction verification [300] (1-): ', 1, null, 300);
				$trademinator_minimum_operation_space_in_seconds = $this->ask_int('[general] Enter the minimum space in seconds after a transaction verification [600] (1-): ', 1, null, 600);				

				$this->data['trademinator']['subscription_email'] = $trademinator_subscription_email;
				$this->data['trademinator']['minimun_profit_percentage'] = $trademinator_minimun_profit_percentage;
				$this->data['trademinator']['risk_percentage'] = $trademinator_risk_percentage;
				$this->data['trademinator']['minimum_points'] = $trademinator_minimum_points;
				$this->data['trademinator']['minimum_non_operation_space_in_seconds'] = $trademinator_minimum_non_operation_space_in_seconds;
				$this->data['trademinator']['minimum_operation_space_in_seconds'] = $trademinator_minimum_operation_space_in_seconds;

				break;
			case 'octobot':
				$octobot_subscription_email = $this->ask_email('Enter your Trademinator subscription email (put your personal email if you do not have a subscription): ');
				$octobot_url = $this->ask_url('Enter your full Octobot Tradeview URL [http://127.0.0.1:9001/webhook/trading_view]: ', 'http://127.0.0.1:9001/webhook/trading_view');
				$octobot_token = $this->ask_string('Enter your Octobot security token: ');
				$octobot_minimum_points = $this->ask_int('[general] Enter the minimum acceptable points transaction [1] (1-): ', 1, null, 1);
				$octobot_minimum_non_operation_space_in_seconds = $this->ask_int('[general] Enter the minimum space in seconds after a non-transaction verification [300] (1-): ', 1, null, 300);
				$octobot_minimum_operation_space_in_seconds = $this->ask_int('[general] Enter the minimum space in seconds after a transaction verification [600] (1-): ', 1, null, 600);				

				$this->data['octobot']['subscription_email'] = $octobot_subscription_email;
				$this->data['octobot']['url'] = $octobot_url;
				$this->data['octobot']['token'] = $octobot_token;
				$this->data['octobot']['minimum_points'] = $trademinator_minimum_points;
				$this->data['octobot']['minimum_non_operation_space_in_seconds'] = $octobot_minimum_non_operation_space_in_seconds;
				$this->data['octobot']['minimum_operation_space_in_seconds'] = $trademinator_minimum_operation_space_in_seconds;
		}

		if (count($this->data['exchanges']) > 0){
			echo 'You have already configured the following exchanges: :'.PHP_EOL;
			foreach ($this->data['exchanges'] as $exchange_name => $exchange_data){
				echo PHP_TAB . '- '.$exchange_name.PHP_EOL;
			}
		}
		else{
			echo 'Let me configure your first exchange.'.PHP_EOL;
		}

		do{
			echo 'Type one of the following: '.implode(', ', \ccxt\Exchange::$exchanges).PHP_EOL;

			// TODO: add a foreach to check for fetch_ohlcv()
			$exchange_name = $this->ask_string('Select your exchange: ', self::force_lowercase, \ccxt\Exchange::$exchanges);
			$exchange_apikey = $this->ask_string('Input your API Key: ');
			$exchange_secret = $this->ask_string('Input your secret: ');
			$exchange_enabled = $this->ask_bool('Enabled by default? [yes]: ', true);

			$this->data['exchanges'][$exchange_name]['apiKey'] = $exchange_apikey;
			$this->data['exchanges'][$exchange_name]['secret'] = $exchange_secret;
			$this->data['exchanges'][$exchange_name]['enabled'] = $exchange_enabled;
			$this->data['exchanges'][$exchange_name]['enableRateLimit'] = true;
		
			$e_n = '\\ccxt\\'.$exchange_name;
			$exchange = new $e_n();
			$markets = $exchange->load_markets();
			echo 'Let me configure your first trading symbol (aka pair)'.PHP_EOL;

			do{
				echo 'Choose one of the following: '.implode(', ', array_keys($markets)).PHP_EOL;
				$symbol = $this->ask_string('Select your symbol: ', self::force_uppercase, array_keys($markets));
				$this->data['exchanges'][$exchange_name]['symbols'][$symbol]['enabled'] = true;

				if ($mode == 'trademinator'){
					$symbol_custom = $this->ask_bool('Do you want to customize this symbol? (otherwise it will take the general parameters) [no]: ', false);

					if ($symbol_custom){
							$symbol_minimun_profit_percentage = $this->ask_int('[general] Enter the minimum profit transaction percentage ['.$trademinator_minimun_profit_percentage.'] (1-100): ', 1, 100, 1);
							$symbol_risk_percentage = $this->ask_int('[general] Enter the risk percentage ['.$trademinator_risk_percentage.'] (1-100): ', 1, 100, 25);
							$symbol_minimum_points = $this->ask_int('[general] Enter the minimum acceptable points transaction ['.$trademinator_minimum_points.'] (1-): ', 1, null, 1);
							$symbol_minimum_non_operation_space_in_seconds = $this->ask_int('[general] Enter the minimum space in seconds after a non-transaction verification ['.$trademinator_minimum_non_operation_space_in_seconds.'] (1-): ', 1, null, 300);
							$symbol_minimum_operation_space_in_seconds = $this->ask_int('[general] Enter the minimum space in seconds after a transaction verification ['.$trademinator_minimum_operation_space_in_seconds.'] (1-): ', 1, null, 600);

							$this->data['exchanges'][$exchange_name]['symbols'][$symbol]['minimun_profit_percentage'] = $symbol_minimun_profit_percentage;
							$this->data['exchanges'][$exchange_name]['symbols'][$symbol]['risk_percentage'] = $symbol_risk_percentage;
							$this->data['exchanges'][$exchange_name]['symbols'][$symbol]['minimum_points'] = $symbol_minimum_points;
							$this->data['exchanges'][$exchange_name]['symbols'][$symbol]['minimum_non_operation_space_in_seconds'] = $symbol_minimum_non_operation_space_in_seconds;
							$this->data['exchanges'][$exchange_name]['symbols'][$symbol]['minimum_operation_space_in_seconds'] = $symbol_minimum_operation_space_in_seconds;
					}
				}
			
				$this->data['exchanges'][$exchange_name]['symbols'][$symbol]['enabled'] = true;
				$another_symbol = $this->ask_bool('Do you want to configure another symbol? [no]: ', false);
			}while($another_symbol);

			$another_exchange = $this->ask_bool('Do you want to configure another exchange? [no]: ', false);
		}while($another_exchange);
		$this->write_config($this->filename);
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

	public function simplify(string $exchange_name, string $symbol){
		$_data = $this->data;
		foreach ($this->data['exchanges'] as $e_name => &$e_info){
			if ($e_name == $exchange_name){
				foreach ($e_info['symbols'] as $s => &$s_info){
					unset($this->data['exchanges'][$e_name]['symbols'][$s]);
				}
			}
			else{
				unset($this->data['exchanges'][$e_name]);
			}
		}

		$s = clone $this;
		$this->data = $_data;

		return $s;
	}

	function safe_value(string $exchange_name, string $symbol, string $key, string $default){
		$mode = $this->data['data'];
		list($base_currency, $market_currency) =  explode('/', $symbol);


		if (array_key_exists($key, $this->data[$mode]['exchanges'][$exchange_name]['symbols'][$symbol])){
			// Look Symbol
			$answer = $this->data[$mode]['exchanges'][$exchange_name]['symbols'][$symbol][$key];
		}
		elseif (array_key_exists($key, $this->data[$mode]['exchanges'][$exchange_name]['symbols']['*/'.$market_currency])){
			// Look *Symbol
			$answer = $this->data[$mode]['exchanges'][$exchange_name]['symbols']['*/'.$market_currency][$key];
		}
		elseif (array_key_exists($key, $this->data[$mode]['exchanges'][$exchange_name])){
			// Look Exchange
			$answer = $this->data[$mode]['exchanges'][$exchange_name][$key];
		}
		elseif (array_key_exists($key, $this->data[$mode])){
			// Look General
			$answer = $this->data[$mode][$key];
		}
		else{
			$answer = $default;
		}

		return $answer;
	}

}
