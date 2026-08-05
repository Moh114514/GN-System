<?php

namespace App\Modules\Agent\Presentation\Livewire;

use App\Modules\Agent\Application\Services\AgentConfigurationCoordinator;
use App\Modules\Agent\Application\Services\AgentManager;
use Carbon\CarbonImmutable;
use DomainException;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('代理商配置')]
class AgentConfiguration extends Component
{
    public ?int $editingTypeId = null;

    public string $typeCode = '';

    public string $typeName = '';

    public string $typeDescription = '';

    public ?int $editingPolicyId = null;

    public string $policyName = '';

    public ?int $editingGradeId = null;

    public string $gradePolicySystemId = '';

    public string $gradeName = '';

    public string $gradeThresholdKrw = '0';

    public string $gradeSortOrder = '0';

    public string $gradeListSort = 'configured';

    public string $ruleGradeId = '';

    public string $ruleInstitutionId = '';

    public string $ruleRateBps = '';

    public string $ruleEffectiveMonth = '';

    public string $ruleListSort = 'effective_desc';

    public string $overrideAgentId = '';

    public string $overrideInstitutionId = '';

    public string $overrideRateBps = '';

    public string $overrideEffectiveMonth = '';

    public string $overrideReason = '';

    public string $overrideListSort = 'effective_desc';

    public function mount(): void
    {
        $nextMonth = now()->addMonthNoOverflow()->startOfMonth()->toDateString();
        $this->ruleEffectiveMonth = $nextMonth;
        $this->overrideEffectiveMonth = $nextMonth;
    }

    public function saveType(AgentManager $manager): void
    {
        $this->validate(['typeCode' => ['required', 'regex:/^[A-Za-z0-9]{2,4}$/'], 'typeName' => ['required', 'string', 'max:255'], 'typeDescription' => ['nullable', 'string', 'max:1000']]);
        $this->run(fn () => $manager->saveType($this->editingTypeId, $this->typeCode, $this->typeName, $this->typeDescription, (int) Auth::id(), request()->ip()), '类型代码已保存。');
        $this->cancelTypeEdit();
    }

    public function editType(int $id, AgentManager $manager): void
    {
        $type = $manager->type($id);
        $this->editingTypeId = $id;
        $this->typeCode = $type['code'];
        $this->typeName = $type['name'];
        $this->typeDescription = (string) ($type['description'] ?? '');
    }

    public function cancelTypeEdit(): void
    {
        $this->reset('editingTypeId', 'typeCode', 'typeName', 'typeDescription');
    }

    public function toggleType(int $id, AgentManager $manager): void
    {
        $this->run(fn () => $manager->toggleType($id, (int) Auth::id(), request()->ip()), '类型代码状态已更新。');
    }

    public function savePolicy(AgentManager $manager): void
    {
        $this->validate(['policyName' => ['required', 'string', 'max:255']]);
        $this->run(fn () => $manager->savePolicy($this->editingPolicyId, $this->policyName, (int) Auth::id(), request()->ip()), '政策体系已保存。');
        $this->cancelPolicyEdit();
    }

    public function editPolicy(int $id, AgentManager $manager): void
    {
        $policy = $manager->policy($id);
        $this->editingPolicyId = $id;
        $this->policyName = $policy['name'];
    }

    public function cancelPolicyEdit(): void
    {
        $this->reset('editingPolicyId', 'policyName');
    }

    public function togglePolicy(int $id, AgentManager $manager): void
    {
        $this->run(fn () => $manager->togglePolicy($id, (int) Auth::id(), request()->ip()), '政策体系状态已更新。');
    }

    public function saveGrade(AgentManager $manager): void
    {
        $this->validate([
            'gradePolicySystemId' => ['required', 'integer'],
            'gradeName' => ['required', 'string', 'max:255'],
            'gradeThresholdKrw' => ['required', 'integer', 'min:0'],
            'gradeSortOrder' => ['required', 'integer', 'min:0', 'max:65535'],
        ]);
        $this->run(fn () => $manager->saveGrade($this->editingGradeId, (int) $this->gradePolicySystemId, $this->gradeName, (int) $this->gradeThresholdKrw, (int) $this->gradeSortOrder, (int) Auth::id(), request()->ip()), '政策等级已保存。');
        $this->cancelGradeEdit();
    }

    public function editGrade(int $id, AgentManager $manager): void
    {
        $grade = $manager->grade($id);
        $this->editingGradeId = $id;
        $this->gradePolicySystemId = (string) $grade['policy_system_id'];
        $this->gradeName = $grade['name'];
        $this->gradeThresholdKrw = (string) $grade['monthly_threshold_krw'];
        $this->gradeSortOrder = (string) $grade['sort_order'];
    }

    public function cancelGradeEdit(): void
    {
        $this->reset('editingGradeId', 'gradePolicySystemId', 'gradeName');
        $this->gradeThresholdKrw = '0';
        $this->gradeSortOrder = '0';
    }

    public function toggleGrade(int $id, AgentManager $manager): void
    {
        $this->run(fn () => $manager->toggleGrade($id, (int) Auth::id(), request()->ip()), '政策等级状态已更新。');
    }

    public function saveRule(AgentConfigurationCoordinator $coordinator): void
    {
        $this->validate([
            'ruleGradeId' => ['required', 'integer'],
            'ruleInstitutionId' => ['required', 'integer'],
            'ruleRateBps' => ['required', 'integer', 'between:0,10000'],
            'ruleEffectiveMonth' => ['required', 'date'],
        ]);
        $this->run(fn () => $coordinator->saveRule((int) $this->ruleGradeId, (int) $this->ruleInstitutionId, (int) $this->ruleRateBps, CarbonImmutable::parse($this->ruleEffectiveMonth), (int) Auth::id(), request()->ip()), '机构费率已保存。');
    }

    public function saveOverride(AgentConfigurationCoordinator $coordinator): void
    {
        $this->validate([
            'overrideAgentId' => ['required', 'integer'],
            'overrideInstitutionId' => ['nullable', 'integer'],
            'overrideRateBps' => ['required', 'integer', 'between:0,10000'],
            'overrideEffectiveMonth' => ['required', 'date'],
            'overrideReason' => ['required', 'string', 'max:1000'],
        ]);
        $this->run(fn () => $coordinator->saveOverride((int) $this->overrideAgentId, $this->overrideInstitutionId === '' ? null : (int) $this->overrideInstitutionId, (int) $this->overrideRateBps, CarbonImmutable::parse($this->overrideEffectiveMonth), $this->overrideReason, (int) Auth::id(), request()->ip()), '代理商特批已保存。');
    }

    public function render(AgentConfigurationCoordinator $coordinator): View
    {
        return view('livewire.agents.agent-configuration', [
            'state' => $coordinator->state(
                $this->gradeListSort,
                $this->ruleListSort,
                $this->overrideListSort,
            ),
        ]);
    }

    private function run(\Closure $operation, string $success): void
    {
        try {
            $operation();
            Flux::toast(variant: 'success', text: $success);
        } catch (DomainException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        }
    }
}
