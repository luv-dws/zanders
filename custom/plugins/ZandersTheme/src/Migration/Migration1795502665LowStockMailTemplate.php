<?php declare(strict_types=1);

namespace ZandersTheme\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * @internal
 */
class Migration1795502665LowStockMailTemplate extends MigrationStep
{
    public const TECHNICAL_NAME = 'ZandersTheme.low_stock_alert';

    public function getCreationTimestamp(): int
    {
        return 1795502665;
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
            'available_entities' => json_encode(['product' => 'product', 'salesChannel' => 'sales_channel']),
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);

        foreach (array_unique(array_filter([$enId, $deId, Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM)])) as $langId) {
            $connection->insert('mail_template_type_translation', [
                'mail_template_type_id' => $id,
                'language_id' => $langId,
                'name' => 'Low stock alert',
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

        $plainText = "Low stock alert for {{ product.translated.name ?? product.name }} ({{ product.productNumber }}).\n"
            . "Available stock: {{ product.availableStock }} (Threshold: {{ threshold }}).";

        $htmlText = '<p>Low stock alert for <strong>{{ product.translated.name ?? product.name }}</strong> '
            . '({{ product.productNumber }})</p>'
            . '<p>Available stock: <strong>{{ product.availableStock }}</strong> (Threshold: {{ threshold }})</p>';

        foreach ($this->getAllLanguageIds($connection) as $langId) {
            $connection->insert('mail_template_translation', [
                'mail_template_id' => $id,
                'language_id' => $langId,
                'sender_name' => '{{ salesChannel.name }}',
                'subject' => 'Low stock alert: {{ product.productNumber }}',
                'description' => 'Notification sent to admin when stock drops below threshold',
                'content_html' => $htmlText,
                'content_plain' => $plainText,
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
