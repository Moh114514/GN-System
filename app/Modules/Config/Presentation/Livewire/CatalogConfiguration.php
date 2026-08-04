<?php

namespace App\Modules\Config\Presentation\Livewire;

use App\Modules\Config\Application\Services\ConfigurationCatalogManager;
use DomainException;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('机构、字典与系统参数')]
class CatalogConfiguration extends Component
{
    public ?int $institutionId = null;

    public string $institutionCode = '';

    public string $institutionName = '';

    public string $institutionAddress = '';

    public string $institutionContactName = '';

    public string $institutionContactValue = '';

    public ?int $dictionaryId = null;

    public string $dictionaryType = 'treatment_project';

    public string $dictionaryCode = '';

    public string $dictionaryName = '';

    public string $reportDefaultPerPage = '50';

    public string $dashboardRefreshSeconds = '300';

    public function mount(ConfigurationCatalogManager $manager): void
    {
        $parameters = $manager->state()['parameters'];
        $this->reportDefaultPerPage = (string) ($parameters['report_default_per_page'] ?? 50);
        $this->dashboardRefreshSeconds = (string) ($parameters['dashboard_refresh_seconds'] ?? 300);
    }

    public function saveInstitution(ConfigurationCatalogManager $manager): void
    {
        $this->validate([
            'institutionCode' => ['required', 'string', 'max:32'],
            'institutionName' => ['required', 'string', 'max:255'],
            'institutionAddress' => ['nullable', 'string', 'max:255'],
            'institutionContactName' => ['nullable', 'string', 'max:255'],
            'institutionContactValue' => ['nullable', 'string', 'max:255'],
        ]);
        $this->run(fn () => $manager->saveInstitution(
            $this->institutionId,
            $this->institutionCode,
            $this->institutionName,
            $this->institutionAddress,
            $this->institutionContactName,
            $this->institutionContactValue,
            (int) Auth::id(),
            request()->ip(),
        ), '机构配置已保存。');
        $this->cancelInstitution();
    }

    public function editInstitution(int $id, ConfigurationCatalogManager $manager): void
    {
        $institution = $manager->institution($id);
        $this->institutionId = (int) $institution['id'];
        $this->institutionCode = (string) $institution['code'];
        $this->institutionName = (string) $institution['name'];
        $this->institutionAddress = (string) ($institution['address'] ?? '');
        $this->institutionContactName = (string) ($institution['contact_name'] ?? '');
        $this->institutionContactValue = (string) ($institution['contact_value'] ?? '');
    }

    public function cancelInstitution(): void
    {
        $this->reset([
            'institutionId', 'institutionCode', 'institutionName', 'institutionAddress',
            'institutionContactName', 'institutionContactValue',
        ]);
    }

    public function toggleInstitution(int $id, ConfigurationCatalogManager $manager): void
    {
        $this->run(fn () => $manager->toggleInstitution($id, (int) Auth::id(), request()->ip()), '机构状态已更新。');
    }

    public function deleteInstitution(int $id, ConfigurationCatalogManager $manager): void
    {
        $this->run(fn () => $manager->deleteInstitution($id, (int) Auth::id(), request()->ip()), '未引用机构已删除。');
    }

    public function saveDictionary(ConfigurationCatalogManager $manager): void
    {
        $this->validate([
            'dictionaryType' => ['required', 'in:treatment_project,translator_language'],
            'dictionaryCode' => ['required', 'string', 'max:64'],
            'dictionaryName' => ['required', 'string', 'max:255'],
        ]);
        $this->run(fn () => $manager->saveDictionaryItem(
            $this->dictionaryId,
            $this->dictionaryType,
            $this->dictionaryCode,
            $this->dictionaryName,
            (int) Auth::id(),
            request()->ip(),
        ), '字典项已保存。');
        $this->reset('dictionaryId', 'dictionaryCode', 'dictionaryName');
    }

    public function editDictionary(int $id, ConfigurationCatalogManager $manager): void
    {
        $item = $manager->dictionaryItem($id);
        $this->dictionaryId = (int) $item['id'];
        $this->dictionaryType = (string) $item['type'];
        $this->dictionaryCode = (string) $item['code'];
        $this->dictionaryName = (string) $item['name'];
    }

    public function toggleDictionary(int $id, ConfigurationCatalogManager $manager): void
    {
        $this->run(fn () => $manager->toggleDictionaryItem($id, (int) Auth::id(), request()->ip()), '字典项状态已更新。');
    }

    public function saveParameters(ConfigurationCatalogManager $manager): void
    {
        $this->validate([
            'reportDefaultPerPage' => ['required', 'integer', 'between:10,200'],
            'dashboardRefreshSeconds' => ['required', 'integer', 'between:60,3600'],
        ]);
        $manager->saveParameter('report_default_per_page', (int) $this->reportDefaultPerPage, (int) Auth::id(), request()->ip());
        $manager->saveParameter('dashboard_refresh_seconds', (int) $this->dashboardRefreshSeconds, (int) Auth::id(), request()->ip());
        Flux::toast(variant: 'success', text: '全局系统参数已保存。');
    }

    public function render(ConfigurationCatalogManager $manager): View
    {
        $state = $manager->state();

        return view('livewire.configuration.catalog-configuration', ['state' => $state]);
    }

    private function run(\Closure $operation, string $success): void
    {
        try {
            $operation();
            Flux::toast(variant: 'success', text: $success);
        } catch (DomainException $exception) {
            $this->addError('configuration', $exception->getMessage());
        }
    }
}
