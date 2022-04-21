# Trademiantor Client
Trademinator is a cryptocurrency bot powered by Artificial Intelligence with a Client / Server architecture that aims to make money while keeping the trading simple.

## Requirements
* PHP 7.4+ (PHP 8.1 recommended)
* API keys rom any supported Exchanges

## Installation
### Simple and easy Way
* Download the PHAR file from https://drive.google.com/drive/folders/1nhSLyf4U7kFa5Ht63zF_YbcaMaOV4TBV?usp=sharing
* Execute it: php trademiantor-XXX.phar

### The developer way
* Clone the directory
* Run composer update
* Execute it: php main.php

## Troubleshoot
### About the Keys
Not all exchanges support API keys, some of then need more information (like NDAX). After running the client for the first time, you will find a trademinator.cfg file, you can add the missing information in the exchange section; please refer to CCXT manual to know details of each exchange.

## Quick FAQ
### What Exchanges do you recommend?
Trademinator has been developed and testing using Bitso and NDAX exchanges (it will work with others if they are supported by the CCXT library). If you want to support the project, please use the following referral links:
* NDAX https://refer.ndax.io/fQdc
* BITSO https://bitso.com/register?ref=bul
