<?php

class MoySkladICMLParser
{
    /**
     * Базовый адрес для запросов
     */
    const BASE_URL = 'https://online.moysklad.ru/exchange/rest/ms/xml';

    /**
     * Таймаут в секундах
     */
    const TIMEOUT = 20;

    /**
     * Шаг для выгрузки элементов в API
     */
    const STEP = 1000;

    /**
     * Адрес для запроса товарных групп
     */
    const GROUP_LIST_URL = '/GoodFolder/list';

    /**
     * Адрес для запроса производителей
     */
    const COMPANY_LIST_URL = '/Company/list';

    /**
     * Адрес для запроса товаров
     */
    const PRODUCT_LIST_URL = '/Good/list';

    /**
     * Адрес для запроса товарных предложений
     */
    const OFFER_LIST_URL = '/Consignment/list';

    /**
     * значение для игнора товарных групп
     */
    const IGNORE_ALL_CATEGORIES = 'all';

    /**
     * Ключ для игнорирования товарных групп
     */
    const OPTION_IGNORE_CATEGORIES = 'ignoreCategories';

    /**
     * идентификтаор из МойСклад
     */
    const UUIDS = 'uuids';

    /**
     * Внешний код из МойСклад
     */
    const EXTERNAL_CODES = 'externalCodes';

    /**
     * @var string $login - логин МойСклад
     */
    protected $login;

    /**
     * @var string $pass - пароль МойСклад
     */
    protected $pass;

    /**
     * @var string $shop - имя магазина
     */
    protected $shop;

    /**
     * @var array $options - дополнительные опции
     */
    protected $options;

    /**
     * @param $login - логин МойСклад
     * @param $pass - пароль МойСклад
     * @param $shop - имя магазина
     * @param array $options - дополнительные опции
     *
     * ключи в $options
     *      // имя выходного ICML файла
     *      'file' => string
     *
     *      // директория для размещения итогового ICML (должна существовать)
     *      'directory' => string
     *
     *      // не загружать торговые предложения
     *      'ignoreOffers' => true
     *
     *      // игнорирование выбранных категорий
     *      'ignoreCategories' => [
     *          // массив uuid товарных групп, которые будут игнориться
     *          'uuids' => array,
     *
     *          // массив внешних кодов товарных групп, которые будут игнориться
     *          'externalCodes' => array,
     *      ]
     *
     *      // игнорирование всех категорий
     *      'ignoreCategories' => 'all'
     *
     *      'ignoreProducts' => [
     *          // массив uuid товаров, которые будут игнориться
     *          // (именно товар, модификации игнорить нельзя)
     *          'uuids' => array,
     *
     *          // массив внешних кодов товарных групп, которые будут игнориться
     *          // (именно товар, модификации игнорить нельзя)
     *          'externalCodes' => array,
     *      ]
     */
    public function __construct(
        $login,
        $pass,
        $shop,
        array $options = array()
    ) {
        $this->login = $login;
        $this->pass = $pass;
        $this->shop = $shop;
        $this->options = $options;
    }

    /**
     * Генерирует ICML файл
     * @return void
     */
    public function generateICML()
    {
        $categories = $this->parseProductGroups();
        $vendors = $this->parseVendors();

        $products = $this->parseProducts($categories, $vendors);

        $icml = $this->createICML($products, $categories);

        $icml->asXML($this->getFilePath());
    }

    /**
     * Парсим товарные группы
     *
     * @return array
     */
    protected function parseProductGroups()
    {
        // если парсинг категорий не требуется
        if (!$this->isProductGroupParseNeed()) {
            return array();
        }

        $categories = array();

        $ignoreInfo = $this->getIgnoreProductGroupsInfo();
        $ignoreUuids = $ignoreInfo[self::UUIDS]; // сюда будут агрегироваться uuid для игнора с учетом вложеностей

        $start = 0;
        $total = 0;
        do {
            $xml = $this->requestXml(self::GROUP_LIST_URL.'?'.http_build_query(array('start' => $start)));

            if ($xml) {

                $total = $xml[0]['total'];

                foreach ($xml->goodFolder as $goodFolder) {
                    $uuid = (string) $goodFolder->uuid;
                    $externalCode = (string) $goodFolder->externalcode;
                    $parentUuid = isset($goodFolder[0]['parentUuid']) ?
                        (string) $goodFolder[0]['parentUuid'] : null;

                    // смотрим игноры
                    if (in_array($uuid, $ignoreInfo[self::UUIDS])) {
                        continue;
                    } elseif (in_array($externalCode, $ignoreInfo[self::EXTERNAL_CODES])) {
                        $ignoreUuids[] = $uuid;
                        continue;
                    } elseif (
                        $parentUuid
                        && in_array($parentUuid, $ignoreUuids)
                    ) {
                        $ignoreUuids[] = $uuid;
                        continue;
                    }

                    $category = array(
                        'uuid' => $uuid,
                        'name' => (string) $goodFolder[0]['name'],
                        'externalCode' => $externalCode,
                    );

                    if (isset($goodFolder[0]['parentUuid'])) {
                        $category['parentUuid'] = (string) $goodFolder[0]['parentUuid'];
                    }

                    $categories[$uuid] = $category;
                }
            } else {
                throw new RuntimeException('No xml - ' . $this->shop);
            }

            $start += self::STEP;
        } while ($start < $total);

        $result = array();
        $this->sortGroupTree($result, $categories);

        return $result;
    }

    /**
     * Парсим производителей
     *
     * @return array
     */
    protected function parseVendors()
    {
        $vendors = array();

        $start = 0;
        $total = 0;
        do {
            $xml = $this->requestXml(self::COMPANY_LIST_URL.'?'.http_build_query(array('start' => $start)));

            if ($xml) {
                $total = $xml[0]['total'];

                foreach ($xml->company as $c) {
                    $uuid = (string) $c->uuid;
                    $name = (string) $c[0]['name'];
                    $vendors[$uuid] = $name;
                }
            } else {
                throw new RuntimeException('No xml - ' . $this->shop);
            }

            $start += self::STEP;
        } while ($start < $total);

        return $vendors;
    }

    /**
     * Парсим товары
     *
     * @param array $categories
     * @param array $vendors
     * @return array
     */
    protected function parseProducts(
        $categories = array(),
        $vendors = array()
    ) {
        $products = array();

        $start = 0;
        $total = 0;
        do {
            $xml = $this->requestXml(self::PRODUCT_LIST_URL.'?'.http_build_query(array('start' => $start)));
            if ($xml) {
                $total = $xml[0]['total'];

                foreach ($xml->good as $v) {

                    $parentUuid = isset($v[0]['parentUuid']) ?
                        (string) $v[0]['parentUuid'] : null;
                    $categoryId = $parentUuid && isset($categories[$parentUuid]) ?
                        $categories[$parentUuid]['externalCode'] : '';
                    $vendorUuid = isset($v[0]['supplierUuid']) ?
                        (string) $v[0]['supplierUuid'] : null;

                    $uuid = (string) $v->uuid;
                    $exCode = (string) $v->externalcode;
                    $products[$uuid] = array(
                        'id' => $exCode, // тут либо externalcode либо uuid товара
                        'exCode' => $exCode, // сюда пишем externalcode
                        'name' => (string) $v[0]['name'],
                        'price' => ((float) $v[0]['salePrice']) / 100,
                        'purchasePrice' => ((float) $v[0]['buyPrice']) / 100,
                        'article' => (string) $v[0]['productCode'],
                        'vendor' => $vendorUuid && isset($vendors[$vendorUuid]) ?
                            $vendors[$vendorUuid] : '',
                        'categoryId' => $categoryId,
                        'offers' => array(),
                    );

                    // Добавление изображений и url из кастомных свойств
                    if (isset($v->attribute)) {
                        foreach ($v->attribute as $attr) {
                            if (isset($attr['valueString']) && stripos($attr['valueString'], 'http') !== false) {
                                if (
                                    stripos($attr['valueString'], '.jpg', 1) !== false ||
                                    stripos($attr['valueString'], '.jpeg', 1) !== false ||
                                    stripos($attr['valueString'], '.gif', 1) !== false ||
                                    stripos($attr['valueString'], '.png', 1) !== false
                                ) {
                                    $products[$uuid]['picture'] = (string) $attr['valueString'];
                                } else {
                                    $products[$uuid]['url'] = (string) $attr['valueString'];
                                }
                            }
                        }
                    }
                }
            } else {
                throw new RuntimeException('No xml - ' . $this->shop);
            }

            $start += self::STEP;
        } while ($start < $total);

        $start = 0;
        $total = 0;
        do {
            if (!$this->isIgnoreOffers()) {
                $xml = $this->requestXml(self::OFFER_LIST_URL.'?'.http_build_query(array('start' => $start)));
                if ($xml) {
                    $total = $xml[0]['total'];

                    foreach ($xml->consignment as $c) {
                        // если нет feature, то товар без торговых предложений
                        if (!isset($c->feature) || !isset($c->feature->attribute)) {
                            continue;
                        }

                        $exCode = (string)$c->feature->externalcode;
                        $name = (string)$c[0]['name'];
                        $pid = (string)$c[0]['goodUuid'];

                        if (isset($products[$pid])) {
                            $products[$pid]['offers'][$exCode] = array(
                                'id' => $products[$pid]['exCode'] . '#' . $exCode,
                                'name' => $name,
                            );
                        } else {
                            // иначе это не товар а услуга (service)
                        }
                    }
                } else {
                    throw new RuntimeException('No xml - ' . $this->shop);
                }
            }

            $start += self::STEP;
        } while ($start < $total);

        // для товаров без торговых преложений
        foreach ($products as $key1 => &$product) {
            // если нет торговых предложений
            if (empty($product['offers'])) {
                $product['offers'][] = array(
                    'id' => $product['exCode'],
                    'name' => $product['name'],
                );
            }
        }

        return $products;
    }

    /**
     * Требуется ли загрузка категорий
     */
    protected function isProductGroupParseNeed()
    {
        if (isset($this->options[self::OPTION_IGNORE_CATEGORIES])) {
            $ignore = $this->options[self::OPTION_IGNORE_CATEGORIES];
            if ($ignore === self::IGNORE_ALL_CATEGORIES) {
                return false;
            }
        }

        return true;
    }

    /**
     * Получаем данные для игнорирования товарных групп
     */
    protected function getIgnoreProductGroupsInfo()
    {
        if (
            !isset($this->options[self::OPTION_IGNORE_CATEGORIES])
            || !is_array($this->options[self::OPTION_IGNORE_CATEGORIES])
        ) {
            $info = array();
        } else {
            $info = $this->options[self::OPTION_IGNORE_CATEGORIES];
        }

        if (
            !isset($info[self::UUIDS])
            || !is_array($info[self::UUIDS])
        ) {
            $info[self::UUIDS] = array();
        }
        if (
            !isset($info[self::EXTERNAL_CODES])
            || !is_array($info[self::EXTERNAL_CODES])
        ) {
            $info[self::EXTERNAL_CODES] = array();
        }

        return $info;
    }

    /**
     * @param string $uri
     * @return SimpleXMLElement
     */
    protected function requestXml($uri)
    {
        $url = self::BASE_URL . $uri;

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, $this->login . ':' . $this->pass);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // возвращаем результат
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT);

        $result = curl_exec($ch);
        curl_close($ch);

        if ($result === false) {
            return null;
        }

        try {
            $xml = new SimpleXMLElement($result);
        } catch (Exception $e) {
            return null;
        }

        return $xml;
    }

    /**
     * Сортируем массив согласно parentId (родитель идет до потомков)
     *
     * @param array &$result
     * @param array $arr
     * @param array $prev
     * @return void
     */
    protected function sortGroupTree(&$result, $arr, $prev = array())
    {
        if (empty($arr)) {
            return;
        }

        $checkPrev = function (&$result, &$prev) {
            foreach ($prev as $key => $elem) {
                if (isset($result[$elem['parentUuid']])) {
                    $result[$elem['uuid']] = $elem;
                    unset($prev[$key]);
                }
            }
        };

        $elem = array_shift($arr);
        if (isset($elem['parentUuid'])) {
            if (isset($result[$elem['parentUuid']])) {
                $result[$elem['uuid']] = $elem;
                $this->sortGroupTree($result, $arr, $prev);

                $checkPrev($result, $prev);
            } else {
                $prev[] = $elem;
                $this->sortGroupTree($result, $arr, $prev);
            }
        } else {
            $result[$elem['uuid']] = $elem;

            $checkPrev($result, $prev);

            $this->sortGroupTree($result, $arr, $prev);
        }
    }

    /**
     * Игнорировать торговые предложения
     */
    protected function isIgnoreOffers()
    {
        if (
            isset($this->options['ignoreOffers'])
            && true === $this->options['ignoreOffers']
        ) {
            return true;
        }

        return false;
    }

    /**
     * Формируем итоговый ICML
     *
     * @param array $products
     * @param array $categories
     * @return SimpleXMLElement
     */
    protected function createICML(
        $products,
        $categories
    ) {
        $date = new DateTime();
        $xmlstr = '<yml_catalog date="'.$date->format('Y-m-d H:i:s').'"><shop><name>'.$this->shop.'</name></shop></yml_catalog>';
        $xml = new SimpleXMLElement($xmlstr);

        if (count($categories)) {
            $categoriesXml = $this->icmlAdd($xml->shop, 'categories', '');
            foreach ($categories as $category) {
                $categoryXml = $this->icmlAdd($categoriesXml, 'category', $category['name']);
                $categoryXml->addAttribute('id', $category['externalCode']);

                if (isset($category['parentUuid']) && $category['parentUuid']) {
                    $parentUuid = $category['parentUuid'];

                    if (isset($categories[$parentUuid])) {
                        $categoryXml->addAttribute('parentId', $categories[$parentUuid]['externalCode']);
                    } else {
                        throw new RuntimeException('Can\'t find category with uuid = \''.$parentUuid.'\'');
                    }
                }
            }
        }

        $offersXml = $this->icmlAdd($xml->shop, 'offers', '');;
        foreach ($products as $key1 => $product) {
            foreach ($product['offers'] as $key2 => $offer) {
                $offerXml = $offersXml->addChild('offer');
                $offerXml->addAttribute('id', $offer['id']);
                $offerXml->addAttribute('productId', $product['id']);

                $this->icmlAdd($offerXml, 'xmlId', $offer['id']);
                $this->icmlAdd($offerXml, 'price', number_format($product['price'], 2, '.', ''));
                $this->icmlAdd($offerXml, 'purchasePrice', number_format($product['purchasePrice'], 2, '.', ''));
                $this->icmlAdd($offerXml, 'name', $offer['name']);
                $this->icmlAdd($offerXml, 'productName', $product['name']);

                if ($product['categoryId']) {
                    $this->icmlAdd($offerXml, 'categoryId', $product['categoryId']);
                }

                if ($product['article']) {
                    $art = $this->icmlAdd($offerXml, 'param', $product['article']);
                    $art->addAttribute('name', 'article');
                }

                if ($product['vendor']) {
                    $this->icmlAdd($offerXml, 'vendor', $product['vendor']);
                }

                if (isset($product['url'])) {
                    $this->icmlAdd($offerXml, 'url', $product['url']);
                }

                if (isset($product['picture'])) {
                    $this->icmlAdd($offerXml, 'picture', $product['picture']);
                }
            }
        }

        return $xml;
    }

    /**
     * Добавляем элемент в icml
     *
     * @param SimpleXMLElement $xml
     * @param string $name
     * @param string $value
     * @return SimpleXMLElement
     */
    protected function icmlAdd(
        SimpleXMLElement $xml,
        $name,
        $value
    ) {
        $elem = $xml->addChild($name);
        if ($value !== '') {
            $elem->{0} = $value;
        }

        return $elem;
    }

    /**
     * Возвращает имя ICML-файла
     * @return string
     */
    protected function getFilePath()
    {
        $path = isset($this->options['directory']) && $this->options['directory'] ?
            $this->options['directory'] : __DIR__;

        if (substr($path, -1) === '/') {
            $path = substr($path, 0, -1);
        }

        $file = isset($this->options['file']) && $this->options['file'] ?
            $this->options['file'] : $this->shop.'.catalog.xml';

        return $path.'/'.$file;
    }
}
