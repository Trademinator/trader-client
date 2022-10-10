# Trademiantor Client
Trademinator is a cryptocurrency bot powered by Artificial Intelligence with a Client / Server architecture that aims to make money while keeping the trading simple.

## Requirements
* PHP 7.4+ (PHP 8.1 recommended)
** https://www.microsoft.com/en-gb/download/details.aspx?id=48145 (if you are using Windows)
* API keys from any supported Exchanges
* A valid subscription (read the FAQ)

## Installation
### Simple and easy Way
* Download the PHAR file from https://drive.google.com/drive/folders/1nhSLyf4U7kFa5Ht63zF_YbcaMaOV4TBV?usp=sharing
* Execute it: php trademiantor-XXX.phar

### The developer way
* Clone the directory
* Run composer update
* Execute it: php main.php

## Troubleshooting
### About the Keys
Not all exchanges support API keys, some of them need more information (like NDAX). After running the client for the first time, you will find a trademinator.cfg file, you can add the missing information in the exchange section; please refer to CCXT manual to know details of each exchange.
### Other errors
The client will create .err files, please read them as they contain details to help you.

## Quick FAQ
### What Exchanges do you recommend?
Trademinator has been developed and testing using Bitso and NDAX exchanges (it will work with others if they are supported by the CCXT library). If you want to support the project, please use the following referral links:
* NDAX https://refer.ndax.io/fQdc
* BITSO https://bitso.com/register?ref=bul
* Coinbase https://www.coinbase.com/join/lucio%20_t

### What are the subscriptions?
For now (while we are in beta test), all available subscriptions are for free. We currently have the following symbols available for trading:
* BTC/MXN on Bitso
* XRP/MXN on Bitso
* ETH/BTC on CoinbasePro
* ADA/CAD on NDAX
* BTC/CAD on NDAX
* DOGE/CAD on NDAX
* XRP/CAD on NDAX

More free symbols will come.

### More FAQs
Read the following links for more answers:
* https://trademinator.com/index.php?option=com_content&view=article&id=5:general-trading-questions-answers&catid=9&Itemid=136
* https://trademinator.com/index.php?option=com_content&view=article&id=8:trademinator-specific-questions-answers&catid=9&Itemid=136
