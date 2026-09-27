<?php

declare(strict_types=1);

namespace SynoUploader\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SynoUploader\Storage\FilenameSanitizer;

final class FilenameSanitizerTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function names(): iterable
    {
        yield 'plain' => ['report.pdf', 'report.pdf'];
        yield 'unicode kept' => ['Résumé 履歴書.docx', 'Résumé 履歴書.docx'];
        yield 'nfd normalized to nfc' => ["Re\u{0301}sume\u{0301}.txt", 'Résumé.txt'];
        yield 'apostrophe and ampersand kept' => ["Tom's & Jerry #1.mp4", "Tom's & Jerry #1.mp4"];
        yield 'path traversal stripped' => ['../../etc/passwd', 'passwd'];
        yield 'windows path stripped' => ['C:\\Users\\x\\file.txt', 'file.txt'];
        yield 'dot only' => ['..', null];
        yield 'single dot' => ['.', null];
        yield 'empty' => ['', null];
        yield 'whitespace only' => ["  \t ", null];
        yield 'leading dots removed' => ['.htaccess', 'htaccess'];
        yield 'trailing dots and spaces' => ['name. . ', 'name'];
        yield 'nul and controls removed' => ["a\x00b\x1Fc\x7F.txt", 'abc.txt'];
        yield 'bidi override removed' => ["invoice\u{202E}fdp.exe", 'invoicefdp.exe'];
        yield 'smb-invalid chars replaced' => ['a<b>c:d"e|f?g*h.txt', 'a_b_c_d_e_f_g_h.txt'];
        yield 'dsm reserved' => ['@eaDir', null];
        yield 'dsm recycle' => ['#recycle', null];
        yield 'invalid utf-8' => ["bad\xC3\x28.txt", null];
        yield 'trailing slash means no name' => ['folder/', null];
    }

    #[DataProvider('names')]
    public function testSanitize(string $input, ?string $expected): void
    {
        self::assertSame($expected, (new FilenameSanitizer())->sanitize($input));
    }

    public function testTruncatesKeepingExtensionAndUtf8(): void
    {
        $sanitizer = new FilenameSanitizer(40);
        $name = str_repeat('日', 30) . '.tar.gz';
        $result = $sanitizer->sanitize($name);
        self::assertNotNull($result);
        self::assertLessThanOrEqual(40, strlen($result));
        self::assertStringEndsWith('.gz', $result);
        self::assertSame(1, preg_match('//u', $result));
    }

    public function testSuffixStaysWithinLimit(): void
    {
        $sanitizer = new FilenameSanitizer(40);
        $name = (string) $sanitizer->sanitize(str_repeat('a', 60) . '.txt');
        $suffixed = $sanitizer->withSuffix($name, 12);
        self::assertLessThanOrEqual(40, strlen($suffixed));
        self::assertStringEndsWith(' (12).txt', $suffixed);
        self::assertSame('photo (1).jpg', (new FilenameSanitizer())->withSuffix('photo.jpg', 1));
        self::assertSame('README (2)', (new FilenameSanitizer())->withSuffix('README', 2));
    }
}
