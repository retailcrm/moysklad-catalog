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
        $countAssortiment = count($assortiment);
        
        if ($countAssortiment > 0) {
            $categories = $this->parserFolder();
            $assortiment = $this->deleteProduct($categories, $assortiment);
        } else {
            $categories = array();
        }

        $icml = $this->ICMLCreate($categories, $assortiment);
        $countCategories = count($categories);
        
        if ($countCategories > 0 && $countAssortiment > 0) {
            $icml->asXML($this->getFilePath());
        }

    }

    /**
     * @param string $url
     * @return JSON
     */
    protected function requestJson($url)
    {
        $downloadImage = strripos($url, 'download');

        $curlHandler = curl_init();
        curl_setopt($curlHandler, CURLOPT_USERPWD, $this->login . ':' . $this->pass);
        curl_setopt($curlHandler, CURLOPT_URL, $url);
        curl_setopt($curlHandler, CURLOPT_FAILONERROR, false);
        curl_setopt($curlHandler, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curlHandler, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curlHandler, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandler, CURLOPT_TIMEOUT, self::TIMEOUT);
        curl_setopt($curlHandler, CURLOPT_CONNECTTIMEOUT, 60);

        if ($downloadImage) {
            curl_setopt($curlHandler, CURLOPT_FOLLOWLOCATION, 1);
        }

        curl_setopt($curlHandler, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json'
            ));

        $responseBody = curl_exec($curlHandler);
        $statusCode = curl_getinfo($curlHandler, CURLINFO_HTTP_CODE);
        $errno = curl_errno($curlHandler);
        $error = curl_error($curlHandler);

        curl_close($curlHandler);
        
        if ($downloadImage) {
            return $responseBody;
        }

        $result = json_decode($responseBody, true);
        
        if ($statusCode >= 400) {
                throw new Exception(
                     $this->getError($result) .
                " [errno = $errno, error = $error]",
                $statusCode
                );
        }

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
        if ($this->noCategory == true) {
            $categories[0] = array(
                'name' => 'warehouseRoot',
                'externalCode' =>'warehouseRoot',
            );
        }

        while (true) {
            
            try { 
                $response = $this->requestJson(self::BASE_URL . self::FOLDER_LIST_URL . '?expand=productFolder&limit=100&offset=' . $offset);
            } catch (Exception $e) {
                echo $e->getMessage();
                return array();
            }
            
            if ($response['rows']) {
                foreach ($response['rows'] as $folder) {
                    if (isset($ignoreCategories['ids']) && is_array($ignoreCategories['ids'])) {

                        if (in_array($folder['id'], $ignoreCategories['id'])) {
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
        $url = self::BASE_URL . self::ASSORT_LIST_URL . '?expand=' . self::ASSORTIMENT_EXPAND . '&limit=' . self::LIMIT;

        $ignoreNoCategoryOffers = isset($this->options['ignoreNoCategoryOffers']) && $this->options['ignoreNoCategoryOffers'];

        $ignoreCategories = $this->getIgnoreProductGroupsInfo();

        if (isset($this->options['archivedGoods']) && $this->options['archivedGoods'] === true) {
            $url .= '&archived=All';
        }

        while (true) {
            try{  
                $response = $this->requestJson($url.'&offset='.$offset);
            } catch (Exception $e) {
                echo $e->getMessage();
                return array();
            }
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

                    if (isset($assortiment['product']['image']['meta']['href'])) {
                        $urlImage = $assortiment['product']['image']['meta']['href'];
                    } elseif (isset($assortiment['image']['meta']['href'])) {
                        $urlImage = $assortiment['image']['meta']['href'];
                    } else {
                        $urlImage = '';
                    }

                    $products[$assortiment['id']] = array(
                        'uuid' => $assortiment['id'],
                        'id' => !empty($assortiment['product']['externalCode']) ?
                            ($assortiment['product']['externalCode'] . '#' . $assortiment['externalCode']) :
                            $assortiment['externalCode'],
                        'exCode' => $assortiment['externalCode'],
                        'productId' => isset($assortiment['product']['externalCode']) ?
                            $assortiment['product']['externalCode'] : $assortiment['externalCode'],
                        'name' => $assortiment['name'],
                        'productName'=> isset($assortiment['product']['name']) ?
                            $assortiment['product']['name'] : $assortiment['name'],
                        'weight' => isset($assortiment['weight']) ?
                            $assortiment['weight'] :
                            $assortiment['product']['weight'],
                        'code' => isset($assortiment['code']) ? (string) $assortiment['code'] : '',
                        'xmlId' => !empty($assortiment['product']['externalCode']) ?
                            ($assortiment['product']['externalCode'] . '#' . $assortiment['externalCode']) :
                            $assortiment['externalCode'],

                        'url' => !empty($assortiment['product']['meta']['uuidHref']) ?
                            $assortiment['product']['meta']['uuidHref'] :
                            (
                                !empty($assortiment['meta']['uuidHref']) ?
                                $assortiment['meta']['uuidHref'] :
                                ''
                            ),
                    );
                    if (isset($this->options['customFields'])) {
                        if (!empty($assortiment['product']['attributes'])) {
                            $products[$assortiment['id']]['customFields'] = $this->getCustomFields($assortiment['product']['attributes']);
                        } elseif (!empty($assortiment['attributes'])){
                            $products[$assortiment['id']]['customFields'] = $this->getCustomFields($assortiment['attributes']);
                        }
                    }

                    if (!empty($assortiment['product']['barcodes'])){
                        $products[$assortiment['id']]['barcodes'] = $assortiment['product']['barcodes'];
                    } elseif (!empty($assortiment['barcodes'])){
                        $products[$assortiment['id']]['barcodes'] = $assortiment['barcodes'];
                    }

                    if (isset($this->options['loadPurchasePrice']) && $this->options['loadPurchasePrice'] === true) {
                        if (isset($assortiment['buyPrice']['value'])) {
                            $products[$assortiment['id']]['purchasePrice'] = (((float)$assortiment['buyPrice']['value']) / 100);
                        } elseif (isset($assortiment['product']['buyPrice']['value'])) {
                           $products[$assortiment['id']]['purchasePrice'] = (((float)$assortiment['product']['buyPrice']['value']) / 100);
                    }
                        $products[$assortiment['id']]['purchasePrice'] = 0;
                    }

                    if (isset($assortiment['salePrices'][0]['value']) && $assortiment['salePrices'][0]['value'] != 0) {
                        $products[$assortiment['id']]['price'] = (((float)$assortiment['salePrices'][0]['value']) / 100);
                    } elseif (isset($assortiment['product']['salePrices'][0]['value'])) {
                        $products[$assortiment['id']]['price'] = (((float)$assortiment['product']['salePrices'][0]['value']) / 100);
                    } else {
                        $products[$assortiment['id']]['price'] = ((float)0);
                    }

                    if (isset($assortiment['uom'])){
                        if (isset($assortiment['uom']['code'])){
                            $products[$assortiment['id']]['unit'] = array (
                                'code' => $assortiment['uom']['code'],
                                'name' => $assortiment['uom']['name'],
                                'description' => $assortiment['uom']['description'],
                            );
                        } elseif (isset($assortiment['uom']['externalCode'])) {
                            $products[$assortiment['id']]['unit'] = array (
                                'code' => $assortiment['uom']['externalCode'],
                                'name' => str_replace(' ', '',$assortiment['uom']['name']),
                                'description' => $assortiment['uom']['name'],
                            );
                        }
                    } elseif (isset($assortiment['product']['uom'])) {
                        if (isset($assortiment['product']['uom']['code'])){
                            $products[$assortiment['id']]['unit'] = array (
                                'code' => $assortiment['product']['uom']['code'],
                                'name' => $assortiment['product']['uom']['name'],
                                'description' => $assortiment['product']['uom']['description'],
                            );
                        } elseif (isset($assortiment['product']['uom']['externalCode'])) {
                            $products[$assortiment['id']]['unit'] = array (
                                'code' => $assortiment['product']['uom']['externalCode'],
                                'name' => str_replace(' ', '',$assortiment['product']['uom']['name']),
                                'description' => $assortiment['product']['uom']['name'],
                            );
                        }
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
                        $products[$assortiment['id']]['categoryId'] = 'warehouseRoot';
                    }

                    if ($products[$assortiment['id']]['categoryId'] == 'warehouseRoot') {
                        $this->noCategory = true;
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
                    
                    if ($urlImage != '') {
                        $products[$assortiment['id']]['image']['imageUrl'] = $urlImage;
                        $products[$assortiment['id']]['image']['name'] = 
                                isset($assortiment['image']['filename']) ? 
                                $assortiment['image']['filename'] : $assortiment['product']['image']['filename'];
                    }
                    
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
        $countCategories = count($categories);
        
        if ($countCategories > 0) {
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
        $countProducts = count($products);
        
        if ($countProducts > 0) {
            foreach ($products as $product) {
                $offerXml = $offersXml->addChild('offer');
                $offerXml->addAttribute('id', $product['id']);
                $offerXml->addAttribute('productId', $product['productId']);

                $this->icmlAdd($offerXml, 'xmlId', $product['xmlId']);
                $this->icmlAdd($offerXml, 'price', number_format($product['price'], 2, '.', ''));

                if (isset($product['purchasePrice'])) {
                    $this->icmlAdd($offerXml, 'purchasePrice', number_format($product['purchasePrice'], 2, '.', ''));
                }

                if (isset($product['barcodes'])) {
                    foreach($product['barcodes'] as $barcode){
                        $this->icmlAdd($offerXml, 'barcode', $barcode);
                    }
                }

                $this->icmlAdd($offerXml, 'name', htmlspecialchars($product['name']));
                $this->icmlAdd($offerXml, 'productName', htmlspecialchars($product['productName']));
                $this->icmlAdd($offerXml, 'vatRate', $product['effectiveVat']);

                if (!empty($product['customFields'])) {
                    if (!empty($product['customFields']['dimensions'])){
                        $this->icmlAdd($offerXml, 'dimensions', $product['customFields']['dimensions']);
                    }
                    if (!empty($product['customFields']['param'])){

                        foreach($product['customFields']['param'] as $param){
                            $art = $this->icmlAdd($offerXml, 'param', $param['value']);
                            $art->addAttribute('code', $param['code']);
                            $art->addAttribute('name', $param['name']);
                        }
                    }
                }

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
                    if (isset($this->options['tagWeight']) && $this->options['tagWeight'] === true) {
                        $wei = $this->icmlAdd($offerXml, 'weight', $product['weight']);
                    } else {
                        $wei = $this->icmlAdd($offerXml, 'param', $product['weight']);
                        $wei->addAttribute('code', 'weight');
                        $wei->addAttribute('name', 'Вес');
                    }
                }

                if ($product['code']) {
                    $cod = $this->icmlAdd($offerXml, 'param', $product['code']);
                    $cod->addAttribute('code', 'code');
                    $cod->addAttribute('name', 'Код');
                }

                if ($product['vendor']) {
                        $this->icmlAdd($offerXml, 'vendor', $product['vendor']);
                }

                if (isset($product['image']['imageUrl']) &&
                    !empty($this->options['imageDownload']['pathToImage']) &&
                    !empty($this->options['imageDownload']['site']))
                {
                    $this->icmlAdd($offerXml, 'picture', $this->saveImage($product['image']));
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
    protected function icmlAdd(SimpleXMLElement $xml,$name, $value) {

        $elem = $xml->addChild($name, $value);

        return $elem;
    }

    /**
     * Возвращает имя ICML-файла
     * 
     * @return string
     */
    protected function getFilePath() {
        
        $path = isset($this->options['directory']) && $this->options['directory'] ?
            $this->options['directory'] : __DIR__;
        $endPath = substr($path, -1);
        
        if ($endPath === '/') {
            $path = substr($path, 0, -1);
        }

        $file = isset($this->options['file']) && $this->options['file'] ?
            $this->options['file'] : $this->shop.'.catalog.xml';

        return $path.'/'.$file;
    }

    /**
     * Получаем данные для игнорирования товарных групп
     */
    protected function getIgnoreProductGroupsInfo() {

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
     * Сохранение изображения в дирректорию на сервере
     * 
     * @param array $image
     * @return string
     */
    protected function saveImage(array $image) {
        
        $root = __DIR__;
        $imgDirrectory = $this->options['imageDownload']['pathToImage'];

        $startPathDirrectory = substr($imgDirrectory,0, 1);

        if ($startPathDirrectory == '/')  {
            $imgDirrectory = substr($imgDirrectory, 1);
        }

        $endPathDirrectory = substr($imgDirrectory, -1);
        
        if ($endPathDirrectory == '/') {
            $imgDirrectory = substr($imgDirrectory, 0, -1);
        }
        
        $imgDirrectoryArray = explode('/', $imgDirrectory);
        $root = stristr($root, $imgDirrectoryArray[0], true);

        if (file_exists($root . '/' . $imgDirrectory) === false) {
            @mkdir($root . '/' . $imgDirrectory);
        }

        if (file_exists($root . $imgDirrectory . '/' . $image['name']) === false) {
            $content = $this->requestJson($image['imageUrl']);

            if ($content) {
                file_put_contents($root .  $imgDirrectory . '/' . $image['name'], $content);
            }
        }

        $imageUrl = $this->linkGeneration($image['name']);

        return $imageUrl;
    }

    /**
     * Генерация ссылки на изображение
     * 
     * @param string $name
     * @return string
     */
    protected function linkGeneration($name) {

        if (empty($name)) { 
            return false;
        }
        $path = $this->options['imageDownload']['pathToImage'];
        
        $startPath = substr($path, 0, 1);
        
        if ($startPath === '/') {
            $path = substr($path, 1); 
        }

        $endPath = substr($path, -1);

        if ($endPath === '/') {
            $path = substr($path, 0, -1);
        }

        $path = explode('/', $path);
        unset($path[0]);
        $path = implode('/', $path);

        $link = $this->options['imageDownload']['site'] . '/' . $path . '/' . $name;

        return $link;
    }
    
    
    /**
     * Get error.
     *
     * @param array
     * @return string
     * @access private
     */
    private function getError($result)
    {
        $error = "";

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $err) {
                if (!empty($err['parameter'])) {
                    $error .= "'" . $err['parameter']."': ".$err['error'];
                } else {
                    $error .= $err['error'];
                }
            }

            unset($err);

            return $error;
        } else {
            if (is_array($result)) {
                foreach ($result as $value) {
                    if (!empty($value['errors'])) {
                        foreach ($value['errors'] as $err) {
                            if (!empty($err['parameter'])) {
                                $error .= "'" . $err['parameter']."': ".$err['error'];
                            } else {
                                $error .= $err['error'];
                            }
                        }

                        unset($err);
                        $error .= " / ";
                    }
                }

                unset($value);
                $error = trim($error, ' /');
                
                if (!empty($error)) {
                    return $error;
                }
            }
        }

        return "Internal server error (" . json_encode($result) . ")";
    }

    /**
     * Получение массива значений кастомных полей.
     *
     * @param array
     * @return array
     * @access private
     */
    protected function getCustomFields($attributes) {

        $result = array();

        if (isset($this->options['customFields']['dimensions'])) {
            if (count($this->options['customFields']['dimensions']) == 3) {
                $maskArray = $this->options['customFields']['dimensions'];

                foreach($attributes as $attribute){
                    if (in_array($attribute['id'], $this->options['customFields']['dimensions'])){
                        $attributeValue[$attribute['id']] = $attribute['value'];
                    }
                }

                $attributeValue = array_merge(array_flip($maskArray),$attributeValue);
                $result['dimensions'] = implode('/', $attributeValue);

            } elseif (count($this->options['customFields']['dimensions']) == 1) {
                if (isset($this->options['customFields']['separate'])){
                    foreach($attributes as $attribute){
                        if (in_array($attribute['id'], $this->options['customFields']['dimensions'])){
                            $result['dimensions'] = str_replace($this->options['customFields']['separate'], '/', $attribute['value']);
                        }
                    }
                }
            }
        }

        if (isset($this->options['customFields']['dimensions'])) {
            if ($this->options['customFields']['paramTag']) {
                foreach ($this->options['customFields']['paramTag'] as $paramTag){
                   $paramTag = explode('#',$paramTag);

                   foreach($attributes as $attribute) {

                        if ($attribute['id'] == $paramTag[1]) {
                            $result['param'][] = array('code' => $paramTag[0],'name' => $attribute['name'], 'value'=> $attribute['value']);
                        }
                    }
                }
            }
        }

        return $result;
    }

    protected function deleteProduct($categories, $products) {
        foreach ($categories as $category) {
            $cat[] = $category['externalCode'];
        }

        foreach ($products as $product) {
            if (!in_array($product['categoryId'],$cat)){
                unset($products[$product['uuid']]);
            }
        }

        return $products;
    }
}
