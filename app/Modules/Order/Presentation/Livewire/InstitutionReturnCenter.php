<?php

namespace App\Modules\Order\Presentation\Livewire;

use App\Modules\Order\Application\Data\InstitutionReturnUploadData;
use App\Modules\Order\Application\Services\InstitutionFormTemplateService;
use App\Modules\Order\Application\Services\InstitutionReturnAccess;
use App\Modules\Order\Application\Services\InstitutionReturnProcessor;
use DomainException;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

#[Layout('layouts.app')]
class InstitutionReturnCenter extends Component
{
    use WithFileUploads;

    public string $institutionId = '';

    public string $customerSearch = '';

    public string $customerId = '';

    /** @var array<string, mixed>|null */
    public ?array $selectedCustomer = null;

    /** @var array<int, array<string, mixed>> */
    public array $customerCandidates = [];

    public ?TemporaryUploadedFile $upload = null;

    /** @var array<int, array{id: int, code: string, name: string}> */
    public array $institutions = [];

    public function mount(InstitutionReturnAccess $access): void
    {
        $this->institutions = array_values($access->activeInstitutions());
    }

    public function updatedCustomerSearch(InstitutionReturnAccess $access): void
    {
        $this->customerCandidates = $access->searchCustomers($this->customerSearch);
    }

    public function selectCustomer(int $customerId, InstitutionReturnAccess $access): void
    {
        $this->selectedCustomer = $access->customer($customerId);
        $this->customerId = (string) $customerId;
        $this->customerSearch = '';
        $this->customerCandidates = [];
        $this->resetValidation('customerId');
    }

    public function clearCustomer(): void
    {
        $this->reset('customerId', 'selectedCustomer');
        $this->customerCandidates = [];
    }

    public function downloadTemplate(InstitutionFormTemplateService $templates): BinaryFileResponse
    {
        $this->validate([
            'institutionId' => ['required', 'integer'],
            'customerId' => ['required', 'integer'],
        ]);
        $generated = $templates->generate((int) $this->institutionId, (int) $this->customerId);

        return response()->download($generated['path'], $generated['filename'])->deleteFileAfterSend();
    }

    public function uploadReturn(InstitutionReturnProcessor $processor): void
    {
        $this->validate([
            'institutionId' => ['required', 'integer'],
            'customerId' => ['required', 'integer'],
            'upload' => ['required', 'file', 'mimes:xlsx,xlsm,xls', 'max:20480'],
        ]);
        $actorId = Auth::id();
        abort_unless(is_int($actorId), 403);
        $contents = $this->upload?->get();
        if ($contents === false || $contents === null) {
            $this->addError('upload', __('orders.errors.institution_form_unreadable'));

            return;
        }

        try {
            $orderId = $processor->upload(new InstitutionReturnUploadData(
                institutionId: (int) $this->institutionId,
                customerId: (int) $this->customerId,
                originalName: $this->upload->getClientOriginalName(),
                extension: strtolower($this->upload->getClientOriginalExtension()),
                mimeType: $this->upload->getMimeType(),
                contents: $contents,
                actorId: $actorId,
                ipAddress: request()->ip(),
            ));
        } catch (DomainException $exception) {
            $this->addError('upload', $exception->getMessage());

            return;
        }

        $this->reset('upload');
        Flux::toast(variant: 'success', text: __('orders.messages.institution_return_processed', ['id' => $orderId]));
    }

    public function render(): View
    {
        return view('livewire.orders.institution-return-center')->title(__('orders.institution_return.title'));
    }
}
