<?php
declare(strict_types=1);

namespace Nova\Services;

use Nova\Services\Insights\RulesInsightProvider;

final class InsightsService
{
    public function __construct(
        private RulesInsightProvider $rules = new RulesInsightProvider(),
    ) {}

    /** @return list<array<string, mixed>> */
    public function list(int $userId): array
    {
        return $this->rules->generate($userId);
    }
}
