<?php

declare(strict_types=1);

namespace MailChannels\Craft;

use craft\events\RegisterComponentTypesEvent;
use craft\helpers\MailerHelper;
use yii\base\Event;

final class Plugin extends \craft\base\Plugin
{
    public function init(): void
    {
        parent::init();
        Event::on(MailerHelper::class, MailerHelper::EVENT_REGISTER_MAILER_TRANSPORTS,
            static function (RegisterComponentTypesEvent $event): void {
                $event->types[] = Adapter::class;
            });
    }
}
