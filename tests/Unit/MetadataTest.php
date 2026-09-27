<?php

declare(strict_types=1);

namespace SynoUploader\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SynoUploader\Tus\Metadata;

final class MetadataTest extends TestCase
{
    public function testParsesPairs(): void
    {
        $header = 'filename ' . base64_encode('a b,c.txt') . ',filetype ' . base64_encode('text/plain') . ',is_confidential';
        self::assertSame(['filename' => 'a b,c.txt', 'filetype' => 'text/plain', 'is_confidential' => ''], Metadata::parse($header));
    }

    public function testEmpty(): void
    {
        self::assertSame([], Metadata::parse(null));
        self::assertSame([], Metadata::parse(''));
    }

    public function testRejectsMalformed(): void
    {
        self::assertNull(Metadata::parse('filename not*base64!'));
        self::assertNull(Metadata::parse('filename a b'));
        self::assertNull(Metadata::parse('a ' . base64_encode('x') . ',a ' . base64_encode('y')));
        self::assertNull(Metadata::parse(' , '));
        self::assertNull(Metadata::parse(str_repeat('a', Metadata::MAX_HEADER_BYTES + 1)));
    }
}
