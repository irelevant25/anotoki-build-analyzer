<?php

namespace Tests\Unit;

use App\Services\Builds\Parsing\AstroIslands;
use App\Services\Builds\Parsing\NextFlight;
use App\Services\Builds\Sources\GenshinGgGenshin;
use App\Services\Builds\Sources\GenshinGgSource;
use App\Services\Http\Fetcher;
use PHPUnit\Framework\TestCase;

/**
 * The three ways build sites embed their data: Astro island props, the Next.js RSC payload and
 * JSON.parse() literals in a JS bundle.
 */
class PageDataParsingTest extends TestCase
{
    public function test_astro_island_props_are_revived(): void
    {
        $props = ['builds' => [1, [[0, ['name' => [0, 'DPS'], 'weapons' => [1, [[0, ['id' => [0, 'staff_of_homa'], 'r' => [0, 5]]]]]]]]], 'lang' => [0, 'en']];
        $attribute = htmlspecialchars(json_encode($props), ENT_QUOTES);
        $html = '<html><body><astro-island component-url="/_astro/Other.js" props="{}"></astro-island>'
            .'<astro-island component-url="/_astro/CharacterBuildTabs.C1.js" props="'.$attribute.'"></astro-island></body></html>';

        $found = AstroIslands::find($html, 'CharacterBuildTabs');

        $this->assertSame('en', $found['lang']);
        $this->assertSame('DPS', $found['builds'][0]['name']);
        $this->assertSame(['id' => 'staff_of_homa', 'r' => 5], $found['builds'][0]['weapons'][0]);
    }

    public function test_next_flight_rows_including_text_rows(): void
    {
        // A text row ("2:T5,hello") is followed directly by the next row without a newline.
        $payload = '0:["$","$L1",null,{"x":1}]'."\n"
            .'2:T5,hello'
            .'3:["$","$L4",null,{"statBuilds":[{"name":"Kafka DoT"}],"lastUpdate":{"build":"3.5"}}]'."\n";
        $chunks = str_split($payload, 20); // the payload is split over several push() calls
        $html = '<html><body>';
        foreach ($chunks as $chunk) {
            $html .= '<script>self.__next_f.push('.json_encode([1, $chunk]).')</script>';
        }
        $html .= '</body></html>';

        $rows = NextFlight::rows(NextFlight::payload($html));
        $this->assertSame('T:hello', $rows['2']);

        $props = NextFlight::findProps($html, 'statBuilds');
        $this->assertSame('Kafka DoT', $props['statBuilds'][0]['name']);
        $this->assertSame('3.5', $props['lastUpdate']['build']);
    }

    public function test_bundle_entries_with_line_breaks_are_separate_options(): void
    {
        $source = new class(new Fetcher) extends GenshinGgGenshin
        {
            public function split(array $entries): array
            {
                return $this->lines($entries);
            }
        };

        $this->assertSame(
            ['Tenacity of the Millelith', 'Noblesse Oblige', 'Crimson Witch of Flames + Gilded Dreams'],
            $source->split(["Tenacity of the Millelith\nNoblesse Oblige", 'Crimson Witch of Flames + Gilded Dreams', '  ']),
        );
    }

    public function test_js_bundle_json_literals_are_decoded(): void
    {
        $js = <<<'JS'
            var a=1;var chars=JSON.parse('[{"name":"Hu Tao","type":"Pyro","weapon":"Polearm","build":{"weapons":["Staff of Homa","The Catch R5"]}}]');
            var w=JSON.parse('[{"name":"Wolf\'s Gravestone","note":"a \\"quoted\\" word\\nnext"}]');
            JS;

        $arrays = GenshinGgSource::decodeBundle($js);

        $this->assertCount(2, $arrays);
        $this->assertSame('Hu Tao', $arrays[0][0]['name']);
        $this->assertSame("Wolf's Gravestone", $arrays[1][0]['name']);
        $this->assertSame("a \"quoted\" word\nnext", $arrays[1][0]['note']);
    }
}
