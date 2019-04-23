# moyskad-catalog

Генератор ICML для каталога из МойСклад

## Использование

1) Выполните include файла `MoySkladICMLParser.php`

2) Сконфигурируйте парсер

```php
$parser = new MoySkladICMLParser(
    'login@moysklad',
    'password',
    'shopname',
    $options
);
```

3) Вызовите метод `generateICML`

```php
$parser->generateICML();
```

Смотрите файл `example.php` в качестве простого примера использования парсера.

## Подробная пошаговая инструкция

Для автоматической генерации каталога товаров на основе продукции из МС Вам понадобится разместить на Вашем сервере небольшой скрипт, который будет заниматься созданием необходимого ICML-файла для retailCRM. Также, после настройки скрипта, необходимо будет добавить задачу в cron.

Как всё настроить:

1) Разместите у себя на сервере в какой-нибудь директории два файла `MoySkladICMLParser.php` и `example.php`.

2) Файл `MoySkladICMLParser.php` ни в коем случае не изменять и не переименовывать!

3) Далее требуется внести необходимые настройки в файл `example.php` (файл можно переименовать, расширение `.php` оставить):

a) вместо `login@moysklad` ввести логин для входа в систему МойСклад (логин сотрудника, для входа в систему управления складом);

b) вместо `password` ввести пароль;

c) `shopname` заменить на название Вашего магазина (или любое другое название);

d) в строке `'file' => 'test.xml'`, заменить `test.xml` на любое другое название (например, `catalog.xml`, расширение файла оставить то же), либо оставить без изменения.

e) При необходимости включения в генерацию архивных товаров и модификаций в строке `'archivedGoods' => false` необходимо заменить значение `false` на `true`.

4) После настройки добавить задачу в cron: `* */4 * * * php /путь_к_файлу_скрипта/example.php` (данная запись подразумевает автоматический запуск генерации файла каталога каждый день раз в 4 часа).

5) Запустить генерацию вручную (командой `php /путь_к_файлу_скрипта/example.php`), чтобы в папке со скриптом появился файл каталога в формате xml.

6) Добавить ссылку на файл в настройках магазина в retailCRM.

## Дополнительные опции

Параметр $options - массив со следующими ключами:

* `file` - Имя файла с итоговым icml без пути (по умолчанию: shopname.catalog.xml)
* `directory` - Директория для итогового icml файла (по умолчанию: текущая директория)
* `archivedGoods` - опция для включения в генерацию архивных товаров и торговых предложений (принимает значения `true` или `false`)
* `ignoreCategories` - массив с ключами:
  * `ids` - Массив c `id` групп товаров, которые должны быть проигнорированы
  * `externalCode` - Массив c `внешними кодами` групп товаров, которые должны быть проигнорированы
* `ignoreNoCategoryOffers` - Если `true` товары, не принадлежащие ни к одной категории, будут проигнорированы
* `imageDownload` - массив, содержащий информацию для загрузки изображений
  * `site` - адрес сайта откуда будут отдаваться изображения в retailCRM
  * `pathToImage` - путь от корня сайта до дирректории где будут храниться изображения
* `tagWeight` - передача веса в теге `weight` вместо `param`. Единица измерения - килограмм. 
Формат: положительное число с точностью 0.001 (или 0.000001, в зависимости от настройки RetailCRM "Точность веса": граммы или миллиграммы соответственно), разделитель целой и дробной части - точка.
Указывается в свойствах товара сервиса Мой Склад.
* `loadPurchasePrice` - установка данной опции со значением `true` включает в генерацию закупочные цены. По умолчанию закупочные цены для товаров не генерируются.
* `service` - установка данного ключа со значение `true` добавляет в генерацию каталога услуги, созданные в сервисе Мой Склад.
* `customFields` - массив для указания для генерации габаритов (dimensions) и дополнительных параметров товаров. Включает в себя следующие опции:
  * `dimensions` - массив с одним или тремя значениями, содержащий id пользовательских полей товара в МС. При указании 3 полей должен соблюдаться порядок 'Длина,Ширина,Высота'. 
Пример заполнения:

    `'dimensions' =>
        array(
            '00000000-0000-0000-0000-000000000000',
            '00000000-0000-0000-0000-000000000000',
            '00000000-0000-0000-0000-000000000000'
        )`

      Если для генерации планируется использовать одно поле, то нужно использовать дополнительный параметр `separate` в котором вы должны указать какой разделитель используется в поле между
значениями на стороне МС. Пример заполнения:
    `
    'separate' => '/',
    'dimensions' =>
        array(
            '00000000-0000-0000-0000-000000000000'
        )
    `

  * `paramTag` - массив со значениями,складывающимися из кода, который должен использоваться для генерации данного дополнительного параметра и id пользовательского поля товара. Заполняется с разделетелем "#" следующим образом:

    `'paramTag'=>
        array(
            'somecode1#00000000-0000-0000-0000-000000000000',
            'somecode2#00000000-0000-0000-0000-000000000000'
        )`

Id пользовательских свойств товара можно получить, совершив GET-запрос к api МС по адресу `https://online.moysklad.ru/api/remap/1.1/entity/product/metadata`, используя для запроса ваш логин и пароль, используемый для генерации каталога.
Необходимые id будут указаны внутри индекса "attributes".
Все доступные опции не обязательны для использования

## Добавление изображения

Изображения сохраняются на сервер клиента!

Для того чтобы добавить в выгрузку изображение товара

В параметре $options необходимо заполнить ключ `imageDownload` массивом со следующими ключ => значениями:
 * `site` - указать адрес сайта в дирректориях которого располагается скрипт с указанием протокола (пример: http://test.ru или http://www.test.ru)
 * `pathToImage` - указать путь до дирректории сохранения изображений от корня сайта с корневой дирректорией сайта включительно (пример: site_root/path/to/directory)
Если дирректория для сохранения изображений ещё не создана, то она будет создана при работе скрипта.
Так же если в дирректории уже есть изображения с таким же названием, что и в сервисе Мой Склад, то данные изображения загружаться не будут, но к ней будет построена ссылка на изображение.
Названия для изображений получаются из ответа сервиса Мой Склад (увидеть название изображения можно в карточке товара). Для торговых предложений изображение берется от товара, которому соответствует данное торговое предложение.

























 
