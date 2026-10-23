<?php

namespace Tests\Unit;

use App\Models\ParseJob;
use App\Services\Notifications\JobReport;
use PHPUnit\Framework\TestCase;

/** Telegram texts about parse jobs: started vs resumed, and what counts as a filter. */
class JobReportTest extends TestCase
{
    private static function job(array $attributes): ParseJob
    {
        $job = new ParseJob;
        $job->forceFill(['id' => 17, 'source' => 'encar', 'triggered_by' => 'admin:admin', ...$attributes]);

        return $job;
    }

    public function test_a_collector_run_with_its_page_checkpoint_is_started_not_resumed(): void
    {
        $progress = ['collector' => 'collector/1', 'checkpoint' => ['seen' => 100], 'resumed' => false, 'api_total' => 201_346, 'found_total' => 100];
        $text = JobReport::started(self::job(['filters' => ['engine' => 'collector'], 'progress' => $progress]));

        $this->assertStringContainsString('запущен', $text);
        $this->assertStringNotContainsString('возобновлён', $text);
        $this->assertStringNotContainsString('engine', $text, 'the engine tag is not a filter');
    }

    public function test_a_resumed_collector_run_says_so(): void
    {
        $progress = ['collector' => 'collector/1', 'checkpoint' => ['seen' => 100], 'resumed' => true];

        $this->assertStringContainsString('возобновлён', JobReport::started(self::job(['progress' => $progress])));
    }

    public function test_old_parser_checkpoint_still_means_resumed_and_real_filters_are_shown(): void
    {
        $text = JobReport::started(self::job(['filters' => ['engine' => 'collector', 'maker' => 'BMW', 'max_pages' => 2], 'progress' => ['checkpoint' => ['page' => 3]]]));

        $this->assertStringContainsString('возобновлён', $text);
        $this->assertStringContainsString('фильтр: maker=BMW, max_pages=2', $text);
    }
}
