# DCE to Content Blocks migration

This TYPO3 extension helps you migrate your DCE elements on TYPO3 v13 to TYPO3 CMS Content Blocks, the official TYPO3 extension to define Content Types.

## Installation

```bash
composer require webcoast/dce-to-contentblocks
```

## Migration

```bash
vendor/bin/typo3 dce:migrate
```

Run this command and select the DCE you would like to migrate and in which extension to save the content block.

## Upgrade Wizard

Write custom RecordDataMigrators to migrate existing DCE elements to the newly created content blocks.

```php
#[AutoconfigureTag('webcoast.dce_to_contentblocks.record_data_migrator')]
#[SourceContentType('dce_dceuid...')] // TODO: set source content type
#[SourceContentType('dce_dceuid...')] // One migrator can have multipe source content types
class ...Migrator extends RecordDataMigrator
{
    protected string $targetContentType = '...'; // TODO: set target content type

    public function migrate(array $flexFormData, array $record): array
    {
        // TODO: return associative array for DataHandler
    }
}
```

### Migrate File Reference

```php
$images = [];
foreach($flexFormData['images'] as $image) {
    if ($image instanceof FileReference) {
        $images[] = $this->updateFileReference($image, 'new_image_field');
    } else if ($image instanceof File) {
        $images[] = $this->addFileReference($image, 'tt_content', 'new_image_field', $record['uid'], $record['pid'], $record['sys_language_uid']);
    }
}
return [
    'new_image_field' => implode(',', $images),
];
```

### Migrate Inline Records

```php
$inlineRecords = [];
$sorting = 8;
foreach ($flexFormData['inline_field'] ?? [] as $link) {
    $inlineRecords[] = $this->addReference('new_inline_table', [
        'title' => $link['title'] ?? '', // e.g.
        'foreign_table_parent_uid' => $record['uid'],
        'sorting' => $sorting,
        'sys_language_uid' => $record['sys_language_uid'],
        'pid' => $record['pid'],
    ]);
    $sorting = $sorting * 2;
}
return [
    'new_inline_field' => implode(',', $inlineRecords),
];
```
