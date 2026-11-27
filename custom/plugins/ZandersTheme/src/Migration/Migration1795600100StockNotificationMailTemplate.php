<?php declare(strict_types=1);

namespace ZandersTheme\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * @internal
 */
class Migration1795600100StockNotificationMailTemplate extends MigrationStep
{
    public const TECHNICAL_NAME = 'zanders.back_in_stock';

    public function getCreationTimestamp(): int
    {
        return 1795600100;
    }

    public function update(Connection $connection): void
    {
        $typeId = $this->getOrCreateMailTemplateType($connection);
        $this->createMailTemplate($connection, $typeId);
    }

    private function getOrCreateMailTemplateType(Connection $connection): string
    {
        $existingId = $connection->fetchOne(
            'SELECT `id` FROM `mail_template_type` WHERE `technical_name` = :technicalName',
            ['technicalName' => self::TECHNICAL_NAME]
        );

        if ($existingId) {
            return (string) $existingId;
        }

        $id = Uuid::randomBytes();
        $enId = $this->getLanguageId($connection, 'en-GB');
        $deId = $this->getLanguageId($connection, 'de-DE');

        $connection->insert('mail_template_type', [
            'id' => $id,
            'technical_name' => self::TECHNICAL_NAME,
            'available_entities' => json_encode([
                'product' => 'product',
                'salesChannel' => 'sales_channel',
            ]),
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $translations = [
            'en-GB' => 'Back in Stock Notification',
            'de-DE' => 'Wieder-vorrätig-Benachrichtigung',
        ];

        if ($enId) {
            $connection->insert('mail_template_type_translation', [
                'mail_template_type_id' => $id,
                'language_id' => $enId,
                'name' => $translations['en-GB'],
                'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]);
        }

        if ($deId && $deId !== $enId) {
            $connection->insert('mail_template_type_translation', [
                'mail_template_type_id' => $id,
                'language_id' => $deId,
                'name' => $translations['de-DE'],
                'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]);
        }

        $systemLangId = Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM);
        if ($systemLangId !== $enId && $systemLangId !== $deId) {
            $connection->insert('mail_template_type_translation', [
                'mail_template_type_id' => $id,
                'language_id' => $systemLangId,
                'name' => $translations['en-GB'],
                'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]);
        }

        return $id;
    }

    private function createMailTemplate(Connection $connection, string $typeId): void
    {
        $exists = $connection->fetchOne(
            'SELECT `id` FROM `mail_template` WHERE `mail_template_type_id` = :typeId',
            ['typeId' => $typeId]
        );

        if ($exists) {
            return;
        }

        $id = Uuid::randomBytes();
        $connection->insert('mail_template', [
            'id' => $id,
            'mail_template_type_id' => $typeId,
            'system_default' => 0,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        $enPlain = "Hello,\n\n"
            . "Good news! The product {{ product.translated.name ?? product.name }} is back in stock!\n\n"
            . "Product Details:\n"
            . "Name: {{ product.translated.name ?? product.name }}\n"
            . "Product Number: {{ product.productNumber }}\n"
            . "{% if product.cover.media.url %}Image: {{ product.cover.media.url }}\n{% endif %}"
            . "View Product: {{ productUrl }}\n\n"
            . "Best regards,\n{{ salesChannel.name }}";

        $enHtml = '<h2>Good news! {{ product.translated.name ?? product.name }} is back in stock!</h2>'
            . '<p>The item you were waiting for is available now.</p>'
            . '{% if product.cover.media.url %}'
            . '<p><a href="{{ productUrl }}"><img src="{{ product.cover.media.url }}" alt="{{ product.translated.name ?? product.name }}" style="max-width: 300px; height: auto;" /></a></p>'
            . '{% endif %}'
            . '<p><strong>Product:</strong> {{ product.translated.name ?? product.name }} ({{ product.productNumber }})</p>'
            . '<p><a href="{{ productUrl }}" style="display:inline-block;padding:10px 20px;background-color:#007bff;color:#ffffff;text-decoration:none;border-radius:4px;">View Product Now</a></p>'
            . '<p>Best regards,<br>{{ salesChannel.name }}</p>';

        $dePlain = "Hallo,\n\n"
            . "Gute Nachrichten! Das Produkt {{ product.translated.name ?? product.name }} ist wieder vorrätig!\n\n"
            . "Produktdetails:\n"
            . "Name: {{ product.translated.name ?? product.name }}\n"
            . "Artikelnummer: {{ product.productNumber }}\n"
            . "{% if product.cover.media.url %}Bild: {{ product.cover.media.url }}\n{% endif %}"
            . "Zum Produkt: {{ productUrl }}\n\n"
            . "Mit freundlichen Grüßen,\n{{ salesChannel.name }}";

        $deHtml = '<h2>Gute Nachrichten! {{ product.translated.name ?? product.name }} ist wieder da!</h2>'
            . '<p>Der gewünschte Artikel ist ab sofort wieder verfügbar.</p>'
            . '{% if product.cover.media.url %}'
            . '<p><a href="{{ productUrl }}"><img src="{{ product.cover.media.url }}" alt="{{ product.translated.name ?? product.name }}" style="max-width: 300px; height: auto;" /></a></p>'
            . '{% endif %}'
            . '<p><strong>Produkt:</strong> {{ product.translated.name ?? product.name }} ({{ product.productNumber }})</p>'
            . '<p><a href="{{ productUrl }}" style="display:inline-block;padding:10px 20px;background-color:#007bff;color:#ffffff;text-decoration:none;border-radius:4px;">Jetzt zum Produkt</a></p>'
            . '<p>Mit freundlichen Grüßen,<br>{{ salesChannel.name }}</p>';

        $enId = $this->getLanguageId($connection, 'en-GB');
        $deId = $this->getLanguageId($connection, 'de-DE');

        foreach ($this->getAllLanguageIds($connection) as $langId) {
            $isDe = ($langId === $deId);
            $connection->insert('mail_template_translation', [
                'mail_template_id' => $id,
                'language_id' => $langId,
                'sender_name' => '{{ salesChannel.name }}',
                'subject' => $isDe ? 'Wieder verfügbar: {{ product.translated.name ?? product.name }}' : 'Back in stock: {{ product.translated.name ?? product.name }}',
                'description' => $isDe ? 'Wieder-vorrätig-Benachrichtigung für Kunden' : 'Customer back-in-stock notification',
                'content_html' => $isDe ? $deHtml : $enHtml,
                'content_plain' => $isDe ? $dePlain : $enPlain,
                'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ]);
        }
    }

    private function getLanguageId(Connection $connection, string $localeCode): ?string
    {
        $id = $connection->fetchOne(
            'SELECT `lang`.`id` FROM `language` `lang`
             INNER JOIN `locale` `loc` ON `lang`.`locale_id` = `loc`.`id`
             WHERE `loc`.`code` = :code',
            ['code' => $localeCode]
        );

        return $id ? (string) $id : null;
    }

    /** @return list<string> */
    private function getAllLanguageIds(Connection $connection): array
    {
        $rows = $connection->fetchFirstColumn('SELECT `id` FROM `language`');

        return array_map('strval', $rows);
    }
}
