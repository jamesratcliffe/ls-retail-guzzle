RetailClient for Lightspeed Retail
==================================

The class is an extension of the Guzzle 6 PHP HTTP Client for use with the Lightspeed Retail API.

It works the same way as the standard Guzzle Client, but takes care of refreshing access tokens and rate limiting.

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

For usage examples, see `index.php`.
