<?php

namespace App\Modules\Order\Presentation\Livewire;

use App\Modules\Order\Application\Data\InstitutionReturnUploadData;
use App\Modules\Order\Application\Services\CustomerOrderRegistrationWorkspace;
use App\Modules\Order\Application\Services\InstitutionFormTemplateService;
use App\Modules\Order\Application\Services\InstitutionReturnProcessor;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CustomerOrderRegistration extends Component
{
    use WithFileUploads;

    public int $customerId;

    public string $institutionId = '';

    public string $status = 'ready';

    public bool $institutionPickerOpen = false;

    public ?TemporaryUploadedFile $upload = null;

    public string $errorMessage = '';

    /** @var array<string, mixed>|null */
    public ?array $successResult = null;

    public function mount(int $customerId, CustomerOrderRegistrationWorkspace $workspace): void
    {
        $this->customerId = $customerId;
        $this->setDefaultInstitution($workspace->context($customerId));
    }

    public function showInstitutionPicker(): void
    {
        $this->institutionPickerOpen = true;
    }

    public function selectInstitution(int $institutionId): void
    {
        $this->institutionId = (string) $institutionId;
        $this->institutionPickerOpen = false;
        $this->resetValidation('institutionId');
    }

    public function downloadTemplate(
        InstitutionFormTemplateService $templates,
        CustomerOrderRegistrationWorkspace $workspace,
    ): BinaryFileResponse {
        $workspace->assertCanRegister($this->customerId);
        $this->validateInstitution($workspace);
        $generated = $templates->generate((int) $this->institutionId, $this->customerId);

        return response()->download($generated['path'], $generated['filename'])->deleteFileAfterSend();
    }

    public function uploadReturn(
        InstitutionReturnProcessor $processor,
        CustomerOrderRegistrationWorkspace $workspace,
    ): void {
        $this->resetValidation();
        $this->validate([
            'institutionId' => ['required', 'integer', 'min:1'],
            'upload' => ['required', 'file', 'mimes:xlsx,xlsm,xls', 'max:20480'],
        ]);
        $workspace->assertCanRegister($this->customerId);
        $workspace->assertActiveInstitution((int) $this->institutionId);
        $this->status = 'uploading';
        $this->errorMessage = '';
        $actorId = Auth::id();
        abort_unless(is_int($actorId), 403);
        $contents = $this->upload?->get();
        if ($contents === false || $contents === null) {
            $this->fail(__('orders.errors.institution_form_unreadable'));

            return;
        }

        try {
            $orderId = $processor->upload(new InstitutionReturnUploadData(
                institutionId: (int) $this->institutionId,
                customerId: $this->customerId,
                originalName: $this->upload->getClientOriginalName(),
                extension: strtolower($this->upload->getClientOriginalExtension()),
                mimeType: $this->upload->getMimeType(),
                contents: $contents,
                actorId: $actorId,
                ipAddress: request()->ip(),
            ));
        } catch (DomainException $exception) {
            $this->fail($exception->getMessage());

            return;
        }

        $this->reset('upload');
        $this->successResult = $workspace->result($this->customerId, $orderId);
        $this->status = 'success';
        $this->dispatch('customer-order-registered', customerId: $this->customerId);
    }

    public function completeRegistration(): void
    {
        if ($this->status === 'success') {
            $this->dispatch('customer-order-registered', customerId: $this->customerId);
        }
    }

    #[On('customer-order-registration-reset')]
    public function resetRegistration(): void
    {
        $this->reset('upload', 'errorMessage', 'successResult', 'institutionPickerOpen');
        $this->resetValidation();
        $this->status = 'ready';
    }

    public function render(CustomerOrderRegistrationWorkspace $workspace): View
    {
        return view('livewire.orders.customer-order-registration', [
            'context' => $workspace->context($this->customerId),
        ]);
    }

    /** @param array<string, mixed> $context */
    private function setDefaultInstitution(array $context): void
    {
        if (($context['institution_locked'] ?? false) && isset($context['institution']['id'])) {
            $this->institutionId = (string) $context['institution']['id'];
        }
    }

    private function validateInstitution(CustomerOrderRegistrationWorkspace $workspace): void
    {
        $this->validate(['institutionId' => ['required', 'integer', 'min:1']]);
        $workspace->assertActiveInstitution((int) $this->institutionId);
    }

    private function fail(string $message): void
    {
        $this->status = 'error';
        $this->errorMessage = $message;
        $this->addError('upload', $message);
    }
}
