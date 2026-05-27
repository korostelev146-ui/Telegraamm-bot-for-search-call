<?php

declare(strict_types=1);

namespace App\Tests\Unit\Source\Sreality;

use PHPUnit\Framework\TestCase;

final class SrealityClientTest extends TestCase
{
    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/../../../Fixtures/' . $name);
        self::assertIsString($contents);

        return $contents;
    }

    public function testFixturesLoadable(): void
    {
        // Smoke test: the four HTML fixtures exist and each contains __NEXT_DATA__.
        foreach (['sreality_search_page.html', 'sreality_detail_private.html', 'sreality_detail_agency.html', 'sreality_detail_minimal.html'] as $name) {
            $content = $this->fixture($name);
            self::assertStringContainsString('__NEXT_DATA__', $content, $name);
        }
    }
}
