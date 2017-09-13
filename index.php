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
var_dump($items['ItemShops']['ItemShop'][0]);

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
var_dump(json_decode($response->getBody(), true));
