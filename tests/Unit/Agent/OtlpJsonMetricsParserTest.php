<?php

namespace Tests\Unit\Agent;

use App\Domain\Monitoring\Agent\MetricSample;
use App\Domain\Monitoring\Agent\OtlpJsonMetricsParser;
use PHPUnit\Framework\TestCase;

class OtlpJsonMetricsParserTest extends TestCase
{
    public function test_real_collector_hostmetrics_payload(): void
    {
        // Exported by otel/opentelemetry-collector-contrib 0.115 (hostmetrics + otlphttp, encoding: json).
        $result = (new OtlpJsonMetricsParser)->parse((string) file_get_contents(__DIR__.'/../../Fixtures/otel/hostmetrics.json'));

        $this->assertTrue($result->ok());
        $this->assertSame(0, $result->rejectedDataPoints);
        $values = $this->byKey($result->samples);
        $this->assertCount(116, $values);

        $this->assertEqualsWithDelta(0.97, $values['system.cpu.load_average.1m{host.name=web1}'], 1e-9);
        $this->assertEqualsWithDelta(0.32086452327, $values['system.memory.utilization{host.name=web1,state=used}'], 1e-9);
        // asInt arrives as a JSON string.
        $this->assertSame(137897250816.0, $values['system.filesystem.usage{device=/dev/sda1,host.name=web1,mode=rw,mountpoint=/,state=used,type=ext4}']);
        $this->assertArrayHasKey('system.cpu.utilization{cpu=cpu0,host.name=web1,state=user}', $values);
        // os.type is a resource attribute outside the allowlist.
        foreach (array_keys($values) as $key) {
            $this->assertStringNotContainsString('os.type', $key);
        }
    }

    public function test_unsupported_types_and_unusable_points_are_counted_not_stored(): void
    {
        $result = (new OtlpJsonMetricsParser(maxAttributes: 2))->parse($this->request([
            ['name' => 'http.server.duration', 'histogram' => ['dataPoints' => [['count' => '3'], ['count' => '1']]]],
            ['name' => 'rpc.latency', 'summary' => ['dataPoints' => [['count' => '3']]]],
            ['name' => 'queue.depth', 'gauge' => ['dataPoints' => [
                ['asInt' => '7'],
                ['flags' => 1],
                ['asDouble' => 'NaN'],
                ['asDouble' => 1.5, 'attributes' => [$this->attr('a', '1'), $this->attr('b', '2'), $this->attr('c', '3')]],
            ]]],
        ]));

        $this->assertTrue($result->ok());
        $this->assertSame(6, $result->rejectedDataPoints);
        $this->assertSame(['queue.depth{host.name=web1}' => 7.0], $this->byKey($result->samples));
    }

    public function test_latest_point_wins_and_attribute_types_become_strings(): void
    {
        $result = (new OtlpJsonMetricsParser)->parse($this->request([
            ['name' => 'temp', 'sum' => ['dataPoints' => [
                ['timeUnixNano' => '200', 'asDouble' => 2, 'attributes' => [['key' => 'probe', 'value' => ['intValue' => '4']], ['key' => 'ok', 'value' => ['boolValue' => true]]]],
                ['timeUnixNano' => '100', 'asDouble' => 1, 'attributes' => [['key' => 'probe', 'value' => ['intValue' => '4']], ['key' => 'ok', 'value' => ['boolValue' => true]]]],
                ['timeUnixNano' => '300', 'asDouble' => 3, 'attributes' => [['key' => 'list', 'value' => ['arrayValue' => ['values' => []]]]]],
            ]]],
        ]));

        $this->assertSame([
            'temp{host.name=web1,ok=true,probe=4}' => 2.0,
            'temp{host.name=web1}' => 3.0,
        ], $this->byKey($result->samples));
        $this->assertSame(0, $result->rejectedDataPoints);
    }

    public function test_long_keys_are_dropped_and_limits_refuse_the_request(): void
    {
        $long = (new OtlpJsonMetricsParser(maxKeyLength: 20))->parse($this->request([
            ['name' => 'a.very.long.metric.name.indeed', 'gauge' => ['dataPoints' => [['asDouble' => 1]]]],
        ]));
        $this->assertSame(1, $long->rejectedDataPoints);
        $this->assertSame([], $long->samples);

        $points = array_fill(0, 3, ['asDouble' => 1]);
        $tooMany = (new OtlpJsonMetricsParser(maxDataPoints: 2))->parse($this->request([['name' => 'x', 'gauge' => ['dataPoints' => $points]]]));
        $this->assertFalse($tooMany->ok());
        $this->assertSame('More than 2 data points.', $tooMany->error);

        $this->assertFalse((new OtlpJsonMetricsParser)->parse('{"resourceMetrics": [')->ok());
        $this->assertFalse((new OtlpJsonMetricsParser)->parse('[1,2]')->ok());
        $this->assertTrue((new OtlpJsonMetricsParser)->parse('{}')->ok());
    }

    /**
     * @param  list<array<string, mixed>>  $metrics
     */
    private function request(array $metrics): string
    {
        return json_encode(['resourceMetrics' => [[
            'resource' => ['attributes' => [$this->attr('host.name', 'web1'), $this->attr('os.type', 'linux')]],
            'scopeMetrics' => [['scope' => ['name' => 'test'], 'metrics' => $metrics]],
        ]]], JSON_THROW_ON_ERROR);
    }

    private function attr(string $key, string $value): array
    {
        return ['key' => $key, 'value' => ['stringValue' => $value]];
    }

    /**
     * @param  list<MetricSample>  $samples
     * @return array<string, float>
     */
    private function byKey(array $samples): array
    {
        $out = [];
        foreach ($samples as $sample) {
            $out[$sample->key] = $sample->value;
        }
        ksort($out);

        return $out;
    }
}
