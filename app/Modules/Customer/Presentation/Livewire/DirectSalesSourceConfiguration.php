<?php

namespace App\Modules\Customer\Presentation\Livewire;

use App\Modules\Customer\Application\Services\DirectSalesSourceManager;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('直销来源配置')]
class DirectSalesSourceConfiguration extends Component
{
    public ?int $editingId = null;

    public string $code = '';

    public string $name = '';

    public function save(DirectSalesSourceManager $manager): void
    {
        $this->validate([
            'code' => ['required', 'regex:/^[A-Za-z0-9]{2,6}$/'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $manager->save($this->editingId, $this->code, $this->name, (int) Auth::id(), request()->ip());
        $this->reset('editingId', 'code', 'name');
        Flux::toast(variant: 'success', text: '直销来源已保存。');
    }

    public function edit(int $id, DirectSalesSourceManager $manager): void
    {
        $source = $manager->find($id);
        $this->editingId = $id;
        $this->code = (string) $source['code'];
        $this->name = (string) $source['name'];
    }

    public function toggle(int $id, DirectSalesSourceManager $manager): void
    {
        $manager->toggle($id, (int) Auth::id(), request()->ip());
        Flux::toast(variant: 'success', text: '直销来源状态已更新。');
    }

    public function render(DirectSalesSourceManager $manager): View
    {
        return view('livewire.customers.direct-sales-source-configuration', ['sources' => $manager->all()]);
    }
}
