# moyskad-catalog

ICML generator for the MoySklad catalog

## Usage

1) Include file `MoySkladICMLParser.php`

2) Configure parser

```php
$parser = new MoySkladICMLParser(
    'login@moysklad',
    'password',
    'shopname',
    $options
);
```

3) Call `generateICML` method

See file `example.php` for simple usage example.

## Options

Options is array with next keys:

* `file` - filename with result icml without path (default: shopname.catalog.xml)
* `directory` - target directory for icml file (default: current directory)
* `ignoreCategories` - array with keys:
  * `ids` - array with GoodFolder `id` for ignore
  * `externalCodes` - array with GoodFolder `externalcode` for ignore
* `ignoreNoCategoryOffers` - If `true` goods that do not belong to any category are ignored

All options keys aren't required