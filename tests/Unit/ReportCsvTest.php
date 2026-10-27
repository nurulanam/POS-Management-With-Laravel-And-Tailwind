<?php

namespace Tests\Unit;

use App\Support\ReportCsv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReportCsvTest extends TestCase
{
    #[DataProvider('unsafeText')]
    public function test_untrusted_text_is_neutralized(string $text): void
    {
        $this->assertSame("'".$text, ReportCsv::text($text));
    }

    public static function unsafeText(): array
    {
        return array_map(fn ($text) => [$text], ['=1+1', '+1', '-1', '@SUM(A1)', '  =1', "\t=1", "\rtext", "\ntext", '＝1', '＋1', '－1', '＠SUM(A1)']);
    }

    public function test_plain_text_and_embedded_quotes_remain_lossless(): void
    {
        $this->assertSame('বাংলা, "Tea"', ReportCsv::text('বাংলা, "Tea"'));
        $this->assertSame('', ReportCsv::text(null));
        $this->assertSame('TEA-01', ReportCsv::text('TEA-01'));
    }
}
