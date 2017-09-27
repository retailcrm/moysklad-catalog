<?php

class MoySkladICMLParser
{
    /**
     * Базовый адрес для запросов
     */
    const BASE_URL = 'https://online.moysklad.ru/api/remap/1.1';

    /**
     * развернутые ссылки в запросе
     */
    const ASSORTIMENT_EXPAND = 'product,productFolder,product.productFolder,supplier,product.supplier,uom,product.uom';

    /**
     * Таймаут в секундах
     */
    const TIMEOUT = 20;

    /**
    * imgur url
    */
    const IMGUR_URL = 'https://api.imgur.com/3/image.json';

    /**
     * Шаг для выгрузки элементов в API
     */
    const STEP = 100;

    /**
     * Лимит выгруженных данных
     */
    const LIMIT = 100;

    /**
     * Адрес для запроса ассортимента
     */
    const ASSORT_LIST_URL = '/entity/assortment';

    /**
     * Адрес для запроса ассортимента
     */
    const FOLDER_LIST_URL = '/entity/productfolder';

    /**
     * @var boolean флаг создания корневой дирректории каталога warehouseRoot
     */
    protected $noCategory;

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
        $assortiment = $this->parseAssortiment();

        $categories = $this->parserFolder();

        if (isset($this->options['imgur']) && isset($this->options['imgur']['clientId'])) {
            $assortiment = $this->uploadImage($assortiment);
        }

        $icml = $this->ICMLCreate($categories, $assortiment);
        
        if (count($categories) > 0 && count($assortiment) > 0) {
            $icml->asXML($this->getFilePath());
        }

    }

    /**
     * @param string $uri
     * @return JSON
     */
    protected function requestJson($uri)
    {
        $url = self::BASE_URL . $uri;

        $curlHandler = curl_init();
        curl_setopt($curlHandler, CURLOPT_USERPWD, $this->login . ':' . $this->pass);
        curl_setopt($curlHandler, CURLOPT_URL, $url);
        curl_setopt($curlHandler, CURLOPT_FAILONERROR, false);
        curl_setopt($curlHandler, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curlHandler, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curlHandler, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandler, CURLOPT_TIMEOUT, self::TIMEOUT);
        curl_setopt($curlHandler, CURLOPT_CONNECTTIMEOUT, 60);
        curl_setopt($curlHandler, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json'
            ));

        $result = curl_exec($curlHandler);
        curl_close($curlHandler);

        if ($result === false) {
            return null;
        }

        $result = json_decode($result,true);

        return $result;
    }

    /**
     * Получение категорий товаров
     *
     * @return array $categories
     */
    protected function parserFolder()
    {
        $offset = 0;
        $end = null;
        $ignoreCategories = $this->getIgnoreProductGroupsInfo();
        if ($this->noCategory==true) {
            $categories[0] = array(
                'name' => 'warehouseRoot',
                'externalCode' =>'warehouseRoot',
            );
        }
        while (true) {
            $response = $this->requestJson(self::FOLDER_LIST_URL.'?expand=productFolder&limit=100&offset='.$offset);


            foreach ($response['rows'] as $folder) {
                if (isset($ignoreCategories['ids']) && is_array($ignoreCategories['ids'])) {
                    if (in_array($folder['id'],$ignoreCategories['id'])) {
                        continue;
                    }
                    if (isset($folder['productFolder']['id'])) {
                        if (in_array($folder['productFolder']['id'],$ignoreCategories['ids'])) {
                            continue;
                        }
                    }
                }

                if (isset($ignoreCategories['externalCode']) && is_array($ignoreCategories['externalCode'])) {
                    if (in_array($folder['externalCode'],$ignoreCategories['externalCode'])) {
                        continue;
                    }
                    if (isset($folder['productFolder']['externalCode'])) {
                        if (in_array($folder['productFolder']['externalCode'],$ignoreCategories['externalCode'])) {
                            continue;
                        }
                    }
                }

                if($folder['archived'] == false) {
                    $categories[] =
                        array(
                            'name' => $folder['name'],
                            'externalCode' => $folder['externalCode'],
                            'parentId' => isset($folder['productFolder']) ?
                                $folder['productFolder']['externalCode'] : '',
                        );

                }
            }

            if (is_null($end)) {
                $end = $response['meta']['size'] - self::STEP;
            } else {
                $end -= self::STEP;
            }

            if ($end >= 0) {
                $offset += self::STEP;
            } else {
                break;
            }
        }

        return $categories;
    }

    /**
     * Получение ассортимента товаров
     *
     * @return array $products
     */
    protected function parseAssortiment()
    {
        $products = array();

        $offset = 0;
        $end = null;
        $url = self::ASSORT_LIST_URL.'?expand='.self::ASSORTIMENT_EXPAND.'&limit='.self::LIMIT;

        $ignoreNoCategoryOffers = isset($this->options['ignoreNoCategoryOffers']) && $this->options['ignoreNoCategoryOffers'];

        $ignoreCategories = $this->getIgnoreProductGroupsInfo();

        if (isset($this->options['archivedGoods']) && $this->options['archivedGoods'] === true) {
            $url .= '&archived=All';
        }

        while (true) {

            $response = $this->requestJson($url.'&offset='.$offset);

            if ($response && $response['rows']) {
                foreach ($response['rows'] as $assortiment) {
                   if (!empty($assortiment['modificationsCount']) ||
                            $assortiment['meta']['type'] == 'service' || 
                            $assortiment['meta']['type'] == 'consignment') {
                            continue;
                        }

                    if ($ignoreNoCategoryOffers === true) {

                        if ( !isset($assortiment['productFolder']['externalCode']) &&
                                !isset($assortiment['product']['productFolder']['externalCode']) ) {
                            continue;
                        }

                    }

                    if (isset($ignoreCategories['ids']) && is_array($ignoreCategories['ids'])) {
                        if (!empty($assortiment['productFolder']['id'])) {
                            if (in_array($assortiment['productFolder']['id'],$ignoreCategories['ids'])) {
                                continue;
                            }
                        }
                        if (!empty($assortiment['product']['productFolder']['id'])) {
                            if (in_array($assortiment['product']['productFolder']['id'],$ignoreCategories['ids'])) {
                                continue;
                            }
                        }
                    }

                    if (isset($ignoreCategories['externalCode']) && is_array($ignoreCategories['externalCode'])) {

                        if (!empty($assortiment['productFolder']['externalCode'])) {
                            if (in_array($assortiment['productFolder']['externalCode'], $ignoreCategories['externalCode'])) {
                                continue;
                            }
                        }

                        if (!empty($assortiment['product']['productFolder']['externalCode'])) {
                            if (in_array($assortiment['product']['productFolder']['externalCode'], $ignoreCategories['externalCode'])) {
                                continue;
                            }
                        }
                    }

                    if (!empty($this->options['imgur'])) {
                        
                        if (isset($assortiment['product']['image']['meta']['href'])) {
                            $imageUrl = $assortiment['product']['image']['meta']['href'];
                        } elseif (isset($assortiment['image']['meta']['href'])) {
                            $imageUrl = $assortiment['image']['meta']['href'];
                        } else {
                            $imageUrl = '';
                        }
                    
                        if ($imageUrl != '') {
                            $image = $this->requestImage($imageUrl);
                        }
                    }
                    
                    $products[$assortiment['id']] = array(
                        'id' => !empty($assortiment['product']['externalCode']) ?
                            ($assortiment['product']['externalCode'] . '#' . $assortiment['externalCode']) :
                            $assortiment['externalCode'],
                        'exCode' => $assortiment['externalCode'],
                        'productId' => isset($assortiment['product']['externalCode']) ?
                            $assortiment['product']['externalCode'] : $assortiment['externalCode'],
                        'name' => $assortiment['name'],
                        'productName'=> isset($assortiment['product']['name'])?
                            $assortiment['product']['name'] : $assortiment['name'],
                        'price' => isset($assortiment['salePrices'][0]['value']) ?
                            (((float)$assortiment['salePrices'][0]['value']) / 100) :
                            (((float)$assortiment['product']['salePrices'][0]['value']) / 100),
                        'purchasePrice' => isset($assortiment['buyPrice']['value']) ?
                            (((float)$assortiment['buyPrice']['value']) / 100) :
                            (
                                isset($assortiment['product']['buyPrice']['value']) ?
                                (((float)$assortiment['product']['buyPrice']['value']) / 100) :
                                0
                            ),
                        'weight' => isset($assortiment['weight']) ?
                            $assortiment['weight'] :
                            $assortiment['product']['weight'],
                        'code' => isset($assortiment['code']) ? (string) $assortiment['code'] : '',
                        'xmlId' => !empty($assortiment['product']['externalCode']) ?
                            ($assortiment['product']['externalCode'] . '#' . $assortiment['externalCode']) :
                            $assortiment['externalCode'],
                        'image' => array(
                            'content' => isset($image) ? $image : '',
                            'name' => isset($assortiment['product']['image']['filename']) ? $assortiment['product']['image']['filename'] : '',
                            'id' => $assortiment['id'],
                        ),
                        'url' => !empty($assortiment['product']['meta']['uuidHref']) ?
                            $assortiment['product']['meta']['uuidHref'] :
                            (
                                !empty($assortiment['meta']['uuidHref']) ?
                                $assortiment['meta']['uuidHref'] :
                                ''
                            )
                    );

                    if (isset($assortiment['uom']) && isset($assortiment['uom']['code'])) {
                        $products[$assortiment['id']]['unit'] = array (
                            'code' => $assortiment['uom']['code'],
                            'name' => $assortiment['uom']['name'],
                            'description' => $assortiment['uom']['description'],
                        );
                    } elseif (isset($assortiment['product']['uom']) && isset($assortiment['product']['uom']['code'])) {
                        $products[$assortiment['id']]['unit'] = array (
                            'code' => $assortiment['product']['uom']['code'],
                            'name' => $assortiment['product']['uom']['name'],
                            'description' => $assortiment['product']['uom']['description'],
                        );
                    } else {
                        $products[$assortiment['id']]['unit'] = '';
                    }

                    if (isset($assortiment['effectiveVat']) && $assortiment['effectiveVat'] != 0) {
                        $products[$assortiment['id']]['effectiveVat'] = $assortiment['effectiveVat'];
                    } elseif (isset($assortiment['product']['effectiveVat']) && $assortiment['product']['effectiveVat'] != 0) {
                        $products[$assortiment['id']]['effectiveVat'] = $assortiment['product']['effectiveVat'];
                    } else {
                        $products[$assortiment['id']]['effectiveVat'] = 'none';
                    }
                    if (isset($assortiment['productFolder']['externalCode'])) {
                        $products[$assortiment['id']]['categoryId'] = $assortiment['productFolder']['externalCode'];
                    } elseif (isset($assortiment['product']['productFolder']['externalCode'])) {
                        $products[$assortiment['id']]['categoryId'] = $assortiment['product']['productFolder']['externalCode'];
                    } else {
                        $products[$assortiment['id']]['categoryId'] = '';
                    }

                    if (isset($assortiment['article'])) {
                        $products[$assortiment['id']]['article'] = (string) $assortiment['article'];
                    } elseif (isset($assortiment['product']['article'])) {
                        $products[$assortiment['id']]['article'] = (string) $assortiment['product']['article'];
                    } else {
                        $products[$assortiment['id']]['article'] = '';
                    }

                    if (isset($assortiment['product']['supplier']['name'])) {
                        $products[$assortiment['id']]['vendor'] = $assortiment['product']['supplier']['name'];
                    } elseif (isset($assortiment['supplier']['name'])) {
                        $products[$assortiment['id']]['vendor'] = $assortiment['supplier']['name'];
                    } else {
                        $products[$assortiment['id']]['vendor'] = '';
                    }

                    if ($products[$assortiment['id']]['categoryId'] == null) {
                        $this->noCategory = true;
                    }
                    unset($image);
                }
            }

            if (is_null($end)) {
                $end = $response['meta']['size'] - self::STEP;
            } else {
                $end -= self::STEP;
            }

            if ($end >= 0) {
                $offset += self::STEP;
            } else {
                break;
            }
        }
        unset($response, $assortiment);

        return $products;
    }

    /**
     * Формируем итоговый ICML
     *
     * @param array $categories
     * @param array $products
     * @return SimpleXMLElement
     */
    protected function ICMLCreate($categories, $products)
    {
        $date = new DateTime();
        $xmlstr = '<yml_catalog date="'.$date->format('Y-m-d H:i:s').'"><shop><name>'.$this->shop.'</name></shop></yml_catalog>';
        $xml = new SimpleXMLElement($xmlstr);
        if (count($categories)) {
            $categoriesXml = $this->icmlAdd($xml->shop, 'categories', '');
            foreach ($categories as $category) {
                $categoryXml = $this->icmlAdd($categoriesXml, 'category', htmlspecialchars($category['name']));
                $categoryXml->addAttribute('id', $category['externalCode']);

                if (!empty($category['parentId'])) {
                    $categoryXml->addAttribute('parentId',$category['parentId']);
                }
            }
        }

        $offersXml = $this->icmlAdd($xml->shop, 'offers', '');
        foreach ($products as $product) {
            $offerXml = $offersXml->addChild('offer');
            $offerXml->addAttribute('id', $product['id']);
            $offerXml->addAttribute('productId', $product['productId']);

            $this->icmlAdd($offerXml, 'xmlId', $product['xmlId']);
            $this->icmlAdd($offerXml, 'price', number_format($product['price'], 2, '.', ''));
            $this->icmlAdd($offerXml, 'purchasePrice', number_format($product['purchasePrice'], 2, '.', ''));
            $this->icmlAdd($offerXml, 'name', htmlspecialchars($product['name']));
            $this->icmlAdd($offerXml, 'productName', htmlspecialchars($product['productName']));
            $this->icmlAdd($offerXml, 'vatRate', $product['effectiveVat']);

            if (!empty($product['url'])) {
                $this->icmlAdd($offerXml, 'url', htmlspecialchars($product['url']));
            }

            if ($product['unit'] != '') {
                $unitXml = $offerXml->addChild('unit');
                $unitXml->addAttribute('code', $product['unit']['code']);
                $unitXml->addAttribute('name', $product['unit']['description']);
                $unitXml->addAttribute('sym', $product['unit']['name']);
            }

            if ($product['categoryId']) {
                    $this->icmlAdd($offerXml, 'categoryId', $product['categoryId']);
                }else {
                    $this->icmlAdd($offerXml, 'categoryId', 'warehouseRoot');
                }

            if ($product['article']) {
                    $art = $this->icmlAdd($offerXml, 'param', $product['article']);
                    $art->addAttribute('code', 'article');
                    $art->addAttribute('name', 'Артикул');
                }

            if ($product['weight']) {
                    $wei = $this->icmlAdd($offerXml, 'param', $product['weight']);
                    $wei->addAttribute('code', 'weight');
                    $wei->addAttribute('name', 'Вес');
                }

            if ($product['code']) {
                    $cod = $this->icmlAdd($offerXml, 'param', $product['code']);
                    $cod->addAttribute('code', 'code');
                    $cod->addAttribute('name', 'Код');
                }

            if ($product['vendor']) {
                    $this->icmlAdd($offerXml, 'vendor', $product['vendor']);
                }

            if (isset($product['image']['url'])) {
                    $this->icmlAdd($offerXml, 'picture', $product['image']['url']);
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

        $elem = $xml->addChild($name, $value);

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

    /**
     * Получаем данные для игнорирования товарных групп
     */
    protected function getIgnoreProductGroupsInfo()
    {

        if (!isset($this->options['ignoreCategories']) || !is_array($this->options['ignoreCategories'])) {
            $info = array();
        } else {
            $info = $this->options['ignoreCategories'];
        }

        if (!isset($info['id']) || !is_array($info['id'])) {
            $info['id'] = array();
        }

        if (!isset($info['externalCode']) || !is_array($info['externalCode'])) {
            $info['externalCode'] = array();
        }

        return $info;
    }

    /**
     * Получаем изображения для товаров
     *
     * @param string $url
     * @return jpg
     */
    protected function requestImage($url)
    {

        $curlHandler = curl_init();
        curl_setopt($curlHandler, CURLOPT_USERPWD, $this->login . ':' . $this->pass);
        curl_setopt($curlHandler, CURLOPT_URL, $url);
        curl_setopt($curlHandler, CURLOPT_FAILONERROR, false);
        curl_setopt($curlHandler, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curlHandler, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curlHandler, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandler, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curlHandler, CURLOPT_TIMEOUT, self::TIMEOUT);
        curl_setopt($curlHandler, CURLOPT_CONNECTTIMEOUT, 60);
        curl_setopt($curlHandler, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json'
            ));

        $result = curl_exec($curlHandler);
        curl_close($curlHandler);

        if ($result === false) {
            return null;
        }

        return $result;
    }

    /**
     * Загружаем изображения
     *
     * @param array $products
     * @return array $products
     */
    protected function uploadImage($products)
    {
        if (file_exists(__DIR__ . '/images') === false) {
            @mkdir(__DIR__ . '/images');
        }

        $uploaded = array();
        if (file_exists(__DIR__ . "/images/{$this->shop}.json")) {
            $uploaded = json_decode(file_get_contents(__DIR__ . "/images/{$this->shop}.json"), true);
        }

        foreach ($products as $id => $product) {
            if (isset($product['image'])) {
                if (isset($uploaded) && isset($uploaded[$product['image']['id']])) {
                    $products[$id]['image']['url'] = $uploaded[$product['image']['id']];
                    unset($product['image']);
                    continue;
                }

                $data = array(
                    'image' => $product['image']['content'],
                    'name'  => $product['image']['name']
                );

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, self::IMGUR_URL);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization: Client-ID {$this->options['imgur']['clientId']}"));
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                $result = curl_exec($ch);
                curl_close($ch);
                $result = @json_decode($result, true);

                if (isset($result['success']) && $result['success'] == true) {
                    $products[$id]['image']['url'] = $result['data']['link'];

                }
                unset($product['image']['content']);
            }
        }

        if (count($uploaded) > 0) {
            file_put_contents(__DIR__ . "/images/{$this->shop}.json", json_encode($uploaded));
        }

        return $products;
    }
}
