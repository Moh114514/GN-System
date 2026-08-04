<?php

namespace App\Modules\Agent\Presentation\Livewire;

use App\Modules\Agent\Application\Data\AgentProfileData;
use App\Modules\Agent\Application\Services\AgentDirectory;
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
#[Title('代理商档案')]
class AgentForm extends Component
{
    public ?int $agentId = null;

    public string $typeCodeId = '';

    public string $codePrefix = '';

    public string $code = '';

    public string $name = '';

    public string $businessRole = '';

    public string $contactName = '';

    public string $contactValue = '';

    public string $cooperationStartedOn = '';

    public string $cooperationEndedOn = '';

    public string $cooperationStatus = 'active';

    public string $policyGradeId = '';

    public string $notes = '';

    /** @var array<string, mixed> */
    public array $options = [];

    public function mount(AgentDirectory $directory, ?int $agent = null): void
    {
        $this->options = $directory->options();
        $this->agentId = $agent;
        if ($agent === null) {
            $this->cooperationStartedOn = now()->toDateString();

            return;
        }
        $profile = $directory->profile($agent);
        $this->typeCodeId = (string) $profile['agent_type_code_id'];
        $this->code = (string) $profile['code'];
        $this->name = (string) $profile['name'];
        $this->businessRole = (string) ($profile['business_role'] ?? '');
        $this->contactName = (string) ($profile['contact_name'] ?? '');
        $this->contactValue = (string) ($profile['contact_value'] ?? '');
        $this->cooperationStartedOn = (string) $profile['cooperation_started_on'];
        $this->cooperationEndedOn = (string) ($profile['cooperation_ended_on'] ?? '');
        $this->cooperationStatus = (string) $profile['cooperation_status'];
        $this->policyGradeId = (string) ($profile['policy_grade_id'] ?? '');
        $this->notes = (string) ($profile['notes'] ?? '');
    }

    public function save(AgentManager $manager): mixed
    {
        $this->validate([
            'typeCodeId' => ['required', 'integer'],
            'codePrefix' => [$this->agentId === null ? 'required' : 'nullable', 'string', 'regex:/^[A-Za-z0-9]{2,8}$/'],
            'name' => ['required', 'string', 'max:255'],
            'businessRole' => ['nullable', 'string', 'max:255'],
            'contactName' => ['nullable', 'string', 'max:255'],
            'contactValue' => ['nullable', 'string', 'max:255'],
            'cooperationStartedOn' => ['required', 'date'],
            'cooperationEndedOn' => [$this->cooperationStatus === 'terminated' ? 'required' : 'nullable', 'date', 'after_or_equal:cooperationStartedOn'],
            'cooperationStatus' => ['required', 'in:active,paused,terminated'],
            'policyGradeId' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $data = new AgentProfileData(
            typeCodeId: (int) $this->typeCodeId,
            codePrefix: $this->codePrefix,
            name: $this->name,
            businessRole: $this->businessRole,
            contactName: $this->contactName,
            contactValue: $this->contactValue,
            cooperationStartedOn: CarbonImmutable::parse($this->cooperationStartedOn),
            cooperationEndedOn: $this->cooperationEndedOn === '' ? null : CarbonImmutable::parse($this->cooperationEndedOn),
            cooperationStatus: $this->cooperationStatus,
            policyGradeId: (int) $this->policyGradeId,
            notes: $this->notes,
        );

        try {
            if ($this->agentId === null) {
                $id = $manager->create($data, (int) Auth::id(), request()->ip());
                Flux::toast(variant: 'success', text: '代理商档案已创建。');

                return $this->redirectRoute('agents.show', ['agent' => $id], navigate: true);
            }
            $manager->update($this->agentId, $data, (int) Auth::id(), request()->ip());
        } catch (DomainException $exception) {
            $this->addError('form', $exception->getMessage());

            return null;
        }
        Flux::toast(variant: 'success', text: '代理商档案已更新；等级变化将在下月生效。');

        return $this->redirectRoute('agents.show', ['agent' => $this->agentId], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.agents.agent-form');
    }
}
