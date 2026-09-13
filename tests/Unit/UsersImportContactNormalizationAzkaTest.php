<?php

namespace Tests\Unit;

use App\Exports\TemplateExport;
use App\Imports\UsersImport;
use Exception;
use PHPUnit\Framework\TestCase;

class UsersImportContactNormalizationAzkaTest extends TestCase
{
    private ContactNormalizationUsersImport $importer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importer = new ContactNormalizationUsersImport;
    }

    public function test_it_normalizes_common_indonesian_phone_formats(): void
    {
        $this->assertSame('081234567890', $this->importer->normalizePhone('+62 812-3456-7890'));
        $this->assertSame('081234567890', $this->importer->normalizePhone(81234567890));
        $this->assertSame('081234567890', $this->importer->normalizePhone('8.123456789E+10'));
        $this->assertSame('081234567890', $this->importer->normalizePhone('0812 3456 7890'));
        $this->assertSame('081234567890', $this->importer->normalizePhone('0062 812 3456 7890'));
    }

    public function test_it_normalizes_and_validates_email_addresses(): void
    {
        $this->assertSame(
            'person@example.com',
            $this->importer->normalizeImportedEmail(' Person@Example.COM '),
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Format email tidak valid');

        $this->importer->normalizeImportedEmail('bukan-email');
    }

    public function test_it_rejects_invalid_phone_numbers(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No. HP hanya boleh berisi angka');

        $this->importer->normalizePhone('0812ABC');
    }

    public function test_it_rejects_fractional_and_boolean_contact_values(): void
    {
        foreach ([81234567890.6, false] as $invalidPhone) {
            try {
                $this->importer->normalizePhone($invalidPhone);
                $this->fail('Nilai No. HP invalid seharusnya ditolak.');
            } catch (Exception $exception) {
                $this->assertSame('No. HP tidak valid', $exception->getMessage());
            }
        }

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Email tidak valid');

        $this->importer->normalizeImportedEmail(false);
    }

    public function test_it_distinguishes_omitted_columns_from_explicitly_blank_columns(): void
    {
        $this->assertFalse($this->importer->containsColumn([], ['email', 'email_address']));
        $this->assertTrue($this->importer->containsColumn(['email' => null], ['email', 'email_address']));
        $this->assertNull($this->importer->normalizeImportedEmail(null));
        $this->assertNull($this->importer->normalizePhone(''));
    }

    public function test_blank_alias_does_not_hide_a_populated_contact_alias(): void
    {
        $this->assertSame(
            '081234567890',
            $this->importer->phoneFromAliases([
                'no_hp' => '',
                'phone' => '+62 812-3456-7890',
            ]),
        );
        $this->assertSame(
            'person@example.com',
            $this->importer->emailFromAliases([
                'email' => null,
                'email_address' => 'Person@Example.com',
            ]),
        );
    }

    public function test_conflicting_contact_aliases_are_rejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No. HP memiliki beberapa nilai yang berbeda');

        $this->importer->phoneFromAliases([
            'no_hp' => '081234567890',
            'phone' => '081298765432',
        ]);
    }

    public function test_official_user_template_includes_contact_columns(): void
    {
        $headings = (new TemplateExport)->headings();

        $this->assertContains('no_hp', $headings);
        $this->assertContains('email', $headings);
    }
}

class ContactNormalizationUsersImport extends UsersImport
{
    public function __construct()
    {
        // Pure normalization tests do not need to initialize a password hash.
    }

    public function normalizePhone(mixed $value): ?string
    {
        return $this->normalizePhoneNumber($value);
    }

    public function normalizeImportedEmail(mixed $value): ?string
    {
        return $this->normalizeEmail($value);
    }

    public function containsColumn(array $row, array $keys): bool
    {
        return $this->hasAnyColumn($row, $keys);
    }

    public function phoneFromAliases(array $row): ?string
    {
        return $this->normalizeContactAliases(
            $row,
            ['no_hp', 'phone'],
            fn (mixed $value): ?string => $this->normalizePhoneNumber($value),
            'No. HP',
        );
    }

    public function emailFromAliases(array $row): ?string
    {
        return $this->normalizeContactAliases(
            $row,
            ['email', 'email_address'],
            fn (mixed $value): ?string => $this->normalizeEmail($value),
            'Email',
        );
    }
}
