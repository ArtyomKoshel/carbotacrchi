<?php

namespace Tests\Unit\Catalog;

use App\Catalog\Che168\Che168Options;
use PHPUnit\Framework\TestCase;

class Che168OptionsTest extends TestCase
{
    private static function options(): Che168Options
    {
        return new Che168Options(dirname(__DIR__, 3) . '/resources/catalog/che168/options.json');
    }

    public function test_a_known_option_is_named_and_grouped(): void
    {
        [$o] = self::options()->describe(['che168:119']);

        $this->assertSame('che168:119', $o['code']);
        $this->assertSame('Подогрев руля', $o['name_ru']);
        $this->assertSame('Heated steering wheel', $o['name_en']);
        $this->assertSame('方向盘加热', $o['name_zh']);
        $this->assertSame('interior', $o['category']);
        $this->assertSame('local_fire_department', $o['icon']);
    }

    public function test_an_unknown_option_keeps_che168s_own_name(): void
    {
        [$o] = self::options()->describe(['che168:999'], ['999' => '座椅通风']);

        $this->assertSame('座椅通风', $o['name_ru']); // shown in Chinese rather than dropped
        $this->assertSame('座椅通风', $o['name_zh']);
        $this->assertNull($o['name_en']);
        $this->assertNull($o['category']);
    }

    public function test_only_che168_codes_are_described(): void
    {
        $this->assertSame([], self::options()->describe(['001', 'che168:', 'encar:1']));
    }

    public function test_every_icon_is_in_the_mini_apps_font(): void
    {
        $html = (string) file_get_contents(dirname(__DIR__, 4) . '/miniapp/index.html');
        preg_match('/icon_names=([a-z0-9_,]+)/', $html, $m);
        $font = explode(',', $m[1] ?? '');
        $file = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/resources/catalog/che168/options.json'), true);

        foreach ($file['options'] as $id => $option) {
            $this->assertContains($option['icon'], $font, "option {$id}: icon {$option['icon']} is not loaded");
            $this->assertContains($option['category'], ['exterior', 'safety', 'convenience', 'interior'], "option {$id}");
        }
    }
}
