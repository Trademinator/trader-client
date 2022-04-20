<?php
namespace OKayInc;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\FirePHPHandler;
use Monolog\Handler\RotatingFileHandler;

class Trademinator{
	const INFO	= 0b00000001;
	const NOTICE	= 0b00000010;
	const WARNING	= 0b00000100;
	const ERROR	= 0b00001000;
	const DEBUG 	= 0b00010000;

	public static $stable_coins = ['USD', 'USDT', 'USDC', 'CAD', 'EUR'];
	public static $version = '0.9.2';

	protected $colour;
	protected $loglevel;
	protected $logger;

	public function __construct(int $loglevel = \OKayInc\Trademinator::INFO|\OKayInc\Trademinator::NOTICE){
		$this->colour = new \Console_Color2();
		$this->loglevel = $loglevel;
		$this->logger = new \Monolog\Logger('Trademinator');
		$this->logger->pushHandler(new \Monolog\Handler\RotatingFileHandler(TRADEMINATOR_ROOTDIR.'/trademinator.log', 0, Logger::WARNING));
		$this->logger->pushHandler(new \Monolog\Handler\RotatingFileHandler(TRADEMINATOR_ROOTDIR.'/trademinator.log', 0, Logger::DEBUG));
		$this->logger->pushHandler(new \Monolog\Handler\RotatingFileHandler(TRADEMINATOR_ROOTDIR.'/trademinator.log', 0, Logger::NOTICE));
		$this->logger->pushHandler(new \Monolog\Handler\RotatingFileHandler(TRADEMINATOR_ROOTDIR.'/trademinator.err', 0, Logger::ERROR));
		$this->logger->pushHandler(new \Monolog\Handler\RotatingFileHandler(TRADEMINATOR_ROOTDIR.'/trademinator.log', 0, Logger::INFO));
	}

	public function log_info(string $_logline){
		if ($this->loglevel & \OKayInc\Trademinator::INFO){
			$this->logger->info($_logline);
			echo $this->colour->convert('%g'.$_logline).PHP_EOL;
		}
	}

	public function log_notice(string $_logline){
		if ($this->loglevel & \OKayInc\Trademinator::NOTICE){
			$this->logger->notice($_logline);
			echo $this->colour->convert('%b'.$_logline).PHP_EOL;
		}
	}

	public function log_warning(string $_logline){
		if ($this->loglevel & \OKayInc\Trademinator::WARNING){
			$this->logger->warning($_logline);
			echo $this->colour->convert('%p'.$_logline).PHP_EOL;
		}
	}

	public function log_error(string $_logline){
		$this->logger->error($_logline);
		echo $this->colour->convert('%r'.$_logline).PHP_EOL;
	}

	public function log_debug(string $_logline){
		if ($this->loglevel & \OKayInc\Trademinator::DEBUG){
			$this->logger->debug($_logline);
			echo $this->colour->convert('%y'.$_logline).PHP_EOL;
		}
	}

}

defined("PHP_TAB") or define("PHP_TAB", "\t");
defined("EXCHANGE_ROUND_DECIMALS") or define('EXCHANGE_ROUND_DECIMALS', 8);
