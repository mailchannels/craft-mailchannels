<?php
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';
require dirname(__DIR__) . '/vendor/craftcms/cms/src/Craft.php';
new yii\console\Application([
    'id' => 'mailchannels-fixture',
    'basePath' => dirname(__DIR__),
    'vendorPath' => dirname(__DIR__) . '/vendor',
    'components' => ['i18n' => ['translations' => ['app' => [
        'class' => yii\i18n\PhpMessageSource::class,
        'basePath' => dirname(__DIR__) . '/vendor/craftcms/cms/src/translations',
    ]]]],
]);
