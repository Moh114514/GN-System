<?php

namespace App\Modules\Order\Presentation\Livewire;

use App\Modules\Order\Application\Data\CompletedOrderItemData;
use App\Modules\Order\Application\Data\CompletedOrderRegistrationData;
use App\Modules\Order\Application\Data\InstitutionReturnUploadData;
use App\Modules\Order\Application\Data\OrderEvidenceUploadData;
use App\Modules\Order\Application\Services\CompletedOrderRegistrar;
use App\Modules\Order\Application\Services\CustomerOrderRegistrationWorkspace;
use App\Modules\Order\Application\Services\InstitutionFormTemplateService;
use App\Modules\Order\Application\Services\InstitutionReturnProcessor;
use Carbon\CarbonImmutable;
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

    /** @var array<int, array{project_name: string, amount_krw: string}> */
    public array $items = [['project_name' => '', 'amount_krw' => '']];

    /** @var array<int, TemporaryUploadedFile> */
    public array $communicationScreenshots = [];

    /** @var array<int, TemporaryUploadedFile> */
    public array $settlementReceipts = [];

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

    public function addItem(): void
    {
        $this->items[] = ['project_name' => '', 'amount_krw' => ''];
    }

    public function removeItem(int $index): void
    {
        if (count($this->items) <= 1) {
            return;
        }

        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function registerOrder(
        CompletedOrderRegistrar $registrar,
        CustomerOrderRegistrationWorkspace $workspace,
    ): void {
        $this->resetValidation();
        $this->validate([
            'institutionId' => ['required', 'integer', 'min:1'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.project_name' => ['required', 'string', 'max:255'],
            'items.*.amount_krw' => ['required', 'integer', 'min:1'],
            'communicationScreenshots' => ['required', 'array', 'min:1'],
            'communicationScreenshots.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:20480'],
            'settlementReceipts' => ['required', 'array', 'min:1'],
            'settlementReceipts.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:20480'],
        ]);
        try {
            $workspace->assertCanRegister($this->customerId);
            $workspace->assertActiveInstitution((int) $this->institutionId);
            $context = $workspace->context($this->customerId);
        } catch (DomainException $exception) {
            $this->fail($exception->getMessage());

            return;
        }
        $arrivedAt = $context['customer']['arrived_at'] ?? null;
        if ($arrivedAt === null) {
            $this->fail(__('orders.errors.customer_not_arrived'));

            return;
        }

        $actorId = Auth::id();
        abort_unless(is_int($actorId), 403);
        $this->status = 'registering';
        $this->errorMessage = '';

        try {
            $orderId = $registrar->register(new CompletedOrderRegistrationData(
                customerId: $this->customerId,
                institutionId: (int) $this->institutionId,
                agentId: (int) $context['customer']['source_agent_id'],
                items: array_map(
                    static fn (array $item): CompletedOrderItemData => new CompletedOrderItemData(
                        projectName: trim($item['project_name']),
                        amountKrw: (int) $item['amount_krw'],
                    ),
                    $this->items,
                ),
                occurredOn: CarbonImmutable::parse($arrivedAt)->startOfDay(),
                actorId: $actorId,
                ipAddress: request()->ip(),
                ownerId: isset($context['customer']['owner_id']) ? (int) $context['customer']['owner_id'] : $actorId,
                source: 'manual_registration',
                evidence: [
                    ...$this->evidence($this->communicationScreenshots, 'communication_screenshot'),
                    ...$this->evidence($this->settlementReceipts, 'settlement_receipt'),
                ],
                requireArrived: true,
                requireEvidence: true,
            ));
        } catch (DomainException $exception) {
            $this->fail($exception->getMessage());

            return;
        }

        $this->reset('communicationScreenshots', 'settlementReceipts');
        $this->successResult = $workspace->result($this->customerId, $orderId);
        $this->status = 'success';
        $this->dispatch('customer-order-registered', customerId: $this->customerId);
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
            'communicationScreenshots' => ['required', 'array', 'min:1'],
            'communicationScreenshots.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:20480'],
            'settlementReceipts' => ['required', 'array', 'min:1'],
            'settlementReceipts.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:20480'],
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
                evidence: [
                    ...$this->evidence($this->communicationScreenshots, 'communication_screenshot'),
                    ...$this->evidence($this->settlementReceipts, 'settlement_receipt'),
                ],
            ));
        } catch (DomainException $exception) {
            $this->fail($exception->getMessage());

            return;
        }

        $this->reset('upload', 'communicationScreenshots', 'settlementReceipts');
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
        $this->reset('upload', 'communicationScreenshots', 'settlementReceipts', 'errorMessage', 'successResult', 'institutionPickerOpen');
        $this->items = [['project_name' => '', 'amount_krw' => '']];
        $this->resetValidation();
        $this->status = 'ready';
    }

    #[On('customer-status-updated')]
    public function refreshAfterStatusChange(int $customerId, CustomerOrderRegistrationWorkspace $workspace): void
    {
        if ($customerId !== $this->customerId) {
            return;
        }

        $this->setDefaultInstitution($workspace->context($this->customerId));
        $this->resetValidation();
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

    /**
     * @param  array<int, TemporaryUploadedFile>  $files
     * @return array<int, OrderEvidenceUploadData>
     */
    private function evidence(array $files, string $type): array
    {
        return array_map(function (TemporaryUploadedFile $file) use ($type): OrderEvidenceUploadData {
            $contents = $file->get();
            if ($contents === false) {
                throw new DomainException(__('orders.errors.order_evidence_unreadable'));
            }

            return new OrderEvidenceUploadData(
                type: $type,
                originalName: $file->getClientOriginalName(),
                mimeType: $file->getMimeType(),
                contents: $contents,
            );
        }, $files);
    }
}
