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
* `ignoreOffers` - if `true` consignment from MoySklad will be ignored
* `ignoreCategories` - string `'all'` or array with keys:
  * `uuids` - array with GoodFolder `uuid` for ignore
  * `externalCodes` - array with GoodFolder `externalcode` for ignore
* `ignoreProducts` - array with keys:
  * `uuids` - array with Good `uuid` for ignore (Consignment can't be ignore)
  * `externalCodes` - array with Good `externalcode` for ignore (Consignment can't be ignore)

All options keys aren't required