<?php

namespace Tests\Unit\Agent;

use App\Domain\Monitoring\Agent\LineProtocolError;
use App\Domain\Monitoring\Agent\LineProtocolParser;
use App\Domain\Monitoring\Agent\MetricSample;
use PHPUnit\Framework\TestCase;

class LineProtocolParserTest extends TestCase
{
    /** Captured from `telegraf --test` (Telegraf 1.32.3, inputs cpu/mem/disk/system), trimmed. */
    private const TELEGRAF = <<<'LP'
mem,host=web1 active=5323718656u,available_percent=62.32649617660177,total=16769032192u,used=5319860224u,used_percent=31.72431278734109 1794064232000000000
system,host=web1 load1=0.58,load15=0.87,load5=0.95,n_cpus=4i 1794064232000000000
system,host=web1 uptime=1276944u 1794064232000000000
system,host=web1 uptime_format="14 days, 18:42" 1794064232000000000
disk,device=overlay,fstype=overlay,host=web1,mode=rw,path=/ free=69096095744u,inodes_used_percent=12.631693317662531,used_percent=66.60144749808093 1794064232000000000
cpu,cpu=cpu-total,host=web1 usage_guest=0,usage_idle=83.66336633686191,usage_system=4.455445543313385,usage_user=8.415841582774693 1794064233000000000
LP;

    public function test_parses_real_telegraf_output(): void
    {
        $result = (new LineProtocolParser())->parse(self::TELEGRAF);

        $this->assertTrue($result->ok());
        $this->assertSame(1, $result->ignoredStringFields);
        $byKey = $this->byKey($result->samples);

        $this->assertEqualsWithDelta(31.72431278734109, $byKey['mem.used_percent{host=web1}']->value, 1e-9);
        $this->assertSame(16769032192.0, $byKey['mem.total{host=web1}']->value);
        $this->assertSame(0.58, $byKey['system.load1{host=web1}']->value);
        $this->assertSame(4.0, $byKey['system.n_cpus{host=web1}']->value);
        $this->assertSame(1276944.0, $byKey['system.uptime{host=web1}']->value);
        $this->assertArrayNotHasKey('system.uptime_format{host=web1}', $byKey);
        $this->assertEqualsWithDelta(66.6014, $byKey['disk.used_percent{device=overlay,fstype=overlay,host=web1,mode=rw,path=/}']->value, 1e-4);
        $this->assertEqualsWithDelta(83.663, $byKey['cpu.usage_idle{cpu=cpu-total,host=web1}']->value, 1e-3);

        $sample = $byKey['mem.used_percent{host=web1}'];
        $this->assertSame('mem', $sample->measurement);
        $this->assertSame('used_percent', $sample->field);
        $this->assertSame(['host' => 'web1'], $sample->tags);
        $this->assertSame(1794064232000000000, $sample->timestampNs);
    }

    public function test_snmp_style_line_with_escapes_booleans_and_quoted_strings(): void
    {
        $line = 'snmp,host=edge,agent_host=10.0.0.1,sysName=core\ switch\,a ifInOctets=123456u,up=true,down=F,descr="uplink, port 1 \"main\"",errors=-3i,rate=1.5e3';
        $result = (new LineProtocolParser())->parse($line);

        $this->assertTrue($result->ok());
        $this->assertSame(1, $result->ignoredStringFields);
        $byKey = $this->byKey($result->samples);
        $tags = '{agent_host=10.0.0.1,host=edge,sysName=core switch,a}';
        $this->assertSame(123456.0, $byKey['snmp.ifInOctets'.$tags]->value);
        $this->assertSame(1.0, $byKey['snmp.up'.$tags]->value);
        $this->assertSame(0.0, $byKey['snmp.down'.$tags]->value);
        $this->assertSame(-3.0, $byKey['snmp.errors'.$tags]->value);
        $this->assertSame(1500.0, $byKey['snmp.rate'.$tags]->value);
        $this->assertNull($byKey['snmp.up'.$tags]->timestampNs);
    }

    public function test_escaped_measurement_tag_and_field_names(): void
    {
        $result = (new LineProtocolParser())->parse('my\ meas,tag\=k=v\=1 field\ one=1.5,f\,2=2');
        $byKey = $this->byKey($result->samples);

        $this->assertArrayHasKey('my meas.field one{tag=k=v=1}', $byKey);
        $this->assertArrayHasKey('my meas.f,2{tag=k=v=1}', $byKey);
    }

    public function test_tags_are_sorted_and_duplicate_keys_keep_the_last_value(): void
    {
        $result = (new LineProtocolParser())->parse("m,z=1,a=2 f=1\r\n# comment\n\nm,a=2,z=1 f=7\n");

        $this->assertCount(1, $result->samples);
        $this->assertSame('m.f{a=2,z=1}', $result->samples[0]->key);
        $this->assertSame(7.0, $result->samples[0]->value);
        $this->assertSame('m.f{a=2,z=1}', MetricSample::canonicalKey('m', 'f', ['z' => '1', 'a' => '2']));
    }

    /**
     * @dataProvider malformedLines
     */
    public function test_malformed_lines_reject_the_payload_without_echoing_it(string $line): void
    {
        $result = (new LineProtocolParser())->parse("ok f=1\n".$line);

        $this->assertFalse($result->ok());
        $this->assertSame([], $result->samples);
        $this->assertSame(LineProtocolError::MALFORMED, $result->error->code);
        $this->assertSame(2, $result->error->line);
        $this->assertSame('Line 2 is not valid line protocol', $result->error->message);
    }

    public static function malformedLines(): array
    {
        return [
            'measurement only' => ['secret_measurement'],
            'field without value' => ['m f='],
            'field without equals' => ['m f'],
            'non numeric value' => ['m f=abc'],
            'bad timestamp' => ['m f=1 notatimestamp'],
            'unterminated string' => ['m f="open'],
            'empty measurement' => [',t=1 f=1'],
            'tag without value' => ['m,t f=1'],
            'too many sections' => ['m f=1 1 2'],
            'unsigned negative' => ['m f=-1u'],
        ];
    }

    public function test_line_count_line_length_and_key_length_limits(): void
    {
        $tooMany = (new LineProtocolParser(maxLines: 2))->parse("a f=1\nb f=1\nc f=1");
        $this->assertSame(LineProtocolError::TOO_MANY_LINES, $tooMany->error->code);
        $this->assertSame(3, $tooMany->error->line);

        $tooLong = (new LineProtocolParser(maxLineLength: 20))->parse('m,host=a-very-long-hostname f=1');
        $this->assertSame(LineProtocolError::LINE_TOO_LONG, $tooLong->error->code);

        $longKey = (new LineProtocolParser(maxKeyLength: 20))->parse('m,host=web1 short=1,a_rather_long_field=2');
        $this->assertTrue($longKey->ok());
        $this->assertSame(1, $longKey->droppedLongKeys);
        $this->assertSame(0, count(array_filter($longKey->samples, static fn ($s) => $s->field === 'a_rather_long_field')));
    }

    public function test_empty_body_is_ok_with_no_samples(): void
    {
        $result = (new LineProtocolParser())->parse("\n# only comments\n");

        $this->assertTrue($result->ok());
        $this->assertSame([], $result->samples);
    }

    /**
     * @param  list<MetricSample>  $samples
     * @return array<string, MetricSample>
     */
    private function byKey(array $samples): array
    {
        $out = [];
        foreach ($samples as $sample) {
            $out[$sample->key] = $sample;
        }

        return $out;
    }
}
