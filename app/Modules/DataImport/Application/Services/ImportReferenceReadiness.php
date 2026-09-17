<?php

namespace App\Modules\DataImport\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentImportGateway;
use App\Modules\Config\Application\Contracts\InstitutionReferenceReader;
use RuntimeException;

final readonly class ImportReferenceReadiness
{
    public function __construct(
        private AgentImportGateway $agents,
        private InstitutionReferenceReader $institutions,
    ) {}

    /**
     * @return array{
     *     ready: bool,
     *     issues: array<int, string>,
     *     agent_types: array<int, array{code: string, name: string}>,
     *     institutions: array<int, array{code: string, name: string}>
     * }
     */
    public function inspect(): array
    {
        $agentTypes = array_values($this->agents->activeAgentTypes());
        $institutions = array_values($this->institutions->activeInstitutions());
        $issues = [];

        if ($agentTypes === []) {
            $issues[] = '缺少启用中的代理商类型';
        }
        if ($institutions === []) {
            $issues[] = '缺少启用中的机构';
        }

        return [
            'ready' => $issues === [],
            'issues' => $issues,
            'agent_types' => $agentTypes,
            'institutions' => $institutions,
        ];
    }

    public function assertReady(): void
    {
        $readiness = $this->inspect();
        if (! $readiness['ready']) {
            throw new RuntimeException('导入基础数据未就绪：'.implode('、', $readiness['issues']));
        }
    }
}
