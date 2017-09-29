# RetailClient for Lightspeed Retail

The class is an extension of the Guzzle 6 PHP HTTP Client for use with the Lightspeed Retail API.

It works the same way as the standard Guzzle Client, but takes care of refreshing access tokens and rate limiting.

**This package was created for demonstration purposes and comes with no waranty.**

## Installation

Use this commmand to install with Composer:

```shell
$ composer require lightspeedhq/ls-retail-guzzle:~1.0
```

Alternatively, you can add these lines to your `composer.json` file:

```json
    "require": {
        "lightspeedhq/ls-retail-guzzle": "~1.0"
    }
```

## Usage Example

```php
<?php
require 'vendor/autoload.php';
use LightspeedHQ\Retail\RetailClient;

// Replace these with your own values for testing.
// API tokens and client credentials should not be stored in your code!
$account_id = XXXXX;
$refresh_token = '****';
$client_id = '****';
$client_secret = '****';

$client = new RetailClient($account_id, $refresh_token, $client_id, $client_secret);

// GET request with some URL paramters. We'll get the first ItemShop
// from this item and dump it.
$query = [
    'load_relations' => '["ItemShops"]',
    'description' => '~,%test%',
    'limit' => 1
];
$response = $client->get('Item', ['query' => $query]);
$items = json_decode($response->getBody(), true)['Item'];
echo '<h3>GET Test</h3>';
echo '<pre>';
var_dump($items['ItemShops']['ItemShop'][0])
echo '</pre>'

// POST request to create an Item
$payload = [
    'description' => 'Rest Test',
    'Prices' => [
        'ItemPrice' => [
            'amount' => 100,
            'useType' => 'Default'
        ]
    ]
];
$response = $client->post('Item', ['json' => $payload]);
echo '<h3>POST Test</h3>';
echo '<pre>';
var_dump(json_decode($response->getBody(), true));
echo '</pre>';
```
