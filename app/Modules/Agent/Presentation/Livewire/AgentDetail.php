<?php

namespace App\Modules\Agent\Presentation\Livewire;

use App\Modules\Agent\Application\Services\AgentDirectory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class AgentDetail extends Component
{
    public int $agentId;

    public function mount(int $agent, AgentDirectory $directory): void
    {
        $directory->profile($agent);
        $this->agentId = $agent;
    }

    public function render(AgentDirectory $directory): View
    {
        return view('livewire.agents.agent-detail', [
            'agent' => $directory->profile($this->agentId),
        ])->title(__('agents.titles.detail'));
    }
}
