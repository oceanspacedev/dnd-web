<?php

namespace Tests\Unit;

use App\Support\WhatsAppNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WhatsAppNumberTest extends TestCase
{
    #[DataProvider('indonesianNumberProvider')]
    public function test_it_normalizes_common_indonesian_number_formats(string $input): void
    {
        $this->assertSame('6281234567890', WhatsAppNumber::normalize($input));
        $this->assertSame('081234567890', WhatsAppNumber::toLocal($input));
    }

    public function test_it_validates_and_masks_canonical_numbers(): void
    {
        $this->assertTrue(WhatsAppNumber::isValid('6281234567890'));
        $this->assertFalse(WhatsAppNumber::isValid('12345'));
        $this->assertNull(WhatsAppNumber::toLocal('12345'));
        $this->assertNull(WhatsAppNumber::toLocal('abc081234567890'));
        $this->assertSame('+62812******90', WhatsAppNumber::mask('6281234567890'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function indonesianNumberProvider(): iterable
    {
        yield 'local' => ['081234567890'];
        yield 'local without zero' => ['81234567890'];
        yield 'country code' => ['6281234567890'];
        yield 'formatted international' => ['+62 812-3456-7890'];
        yield 'international dialling prefix' => ['0062 812 3456 7890'];
        yield 'country code with extra zero' => ['62081234567890'];
    }
}
