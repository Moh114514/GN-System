<?php

namespace App\Modules\Order\Presentation\Livewire;

use App\Modules\Order\Application\Contracts\OrderRegistrationDraftGateway;
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
use Illuminate\Support\Str;
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

    public string $submissionKey = '';

    public ?string $draftId = null;

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

    public function mount(
        int $customerId,
        CustomerOrderRegistrationWorkspace $workspace,
        OrderRegistrationDraftGateway $drafts,
    ): void {
        $this->customerId = $customerId;
        $this->setDefaultInstitution($workspace->context($customerId));
        $this->submissionKey = (string) Str::uuid();
        $draft = $drafts->latestOpen($customerId);
        if ($draft !== null) {
            $this->submissionKey = $draft['id'];
            $this->draftId = $draft['id'];
            $this->institutionId = $draft['institution_id'] === null ? $this->institutionId : (string) $draft['institution_id'];
            $items = $draft['payload']['items'] ?? null;
            if (is_array($items) && $items !== []) {
                $this->items = array_values(array_map(static fn (mixed $item): array => [
                    'project_name' => is_array($item) ? (string) ($item['project_name'] ?? '') : '',
                    'amount_krw' => is_array($item) ? (string) ($item['amount_krw'] ?? '') : '',
                ], $items));
            }
        }
    }

    public function saveDraft(
        OrderRegistrationDraftGateway $drafts,
        CustomerOrderRegistrationWorkspace $workspace,
    ): void {
        $workspace->assertCanSaveDraft($this->customerId);
        $this->validate([
            'institutionId' => ['nullable', 'integer', 'min:1'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.project_name' => ['nullable', 'string', 'max:255'],
            'items.*.amount_krw' => ['nullable', 'string', 'max:20'],
        ]);
        $context = $workspace->context($this->customerId);
        $actorId = Auth::id();
        abort_unless(is_int($actorId), 403);
        if (! Str::isUuid($this->submissionKey)) {
            $this->submissionKey = (string) Str::uuid();
        }
        $draftItems = array_values(array_map(static fn (array $item): array => [
            'project_name' => $item['project_name'],
            'amount_krw' => $item['amount_krw'],
        ], $this->items));
        $appointmentId = $context['appointment']['id'] ?? null;
        $institutionId = $this->institutionId !== '' && ctype_digit($this->institutionId)
            ? (int) $this->institutionId
            : null;
        $drafts->save(
            draftId: $this->submissionKey,
            customerId: $this->customerId,
            appointmentId: $appointmentId === null ? null : (int) $appointmentId,
            institutionId: $institutionId,
            payload: ['items' => $draftItems],
            actorId: $actorId,
        );
        $this->draftId = $this->submissionKey;
        $this->status = 'draft_saved';
        $this->errorMessage = '';
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
            'communicationScreenshots' => ['required_without:settlementReceipts', 'array'],
            'communicationScreenshots.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:20480'],
            'settlementReceipts' => ['required_without:communicationScreenshots', 'array'],
            'settlementReceipts.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:20480'],
        ], [
            'communicationScreenshots.required_without' => __('orders.errors.order_evidence_required'),
            'settlementReceipts.required_without' => __('orders.errors.order_evidence_required'),
        ]);
        try {
            $workspace->assertCanRegister($this->customerId);
            $workspace->assertActiveInstitution((int) $this->institutionId);
            $workspace->assertVisitInstitution($this->customerId, (int) $this->institutionId);
            $context = $workspace->context($this->customerId);
        } catch (DomainException $exception) {
            $this->fail($exception->getMessage());

            return;
        }
        $arrivedAt = $context['visit_arrived_at'] ?? null;
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
                agentId: ($context['customer']['source_agent_id'] ?? null) === null
                    ? null
                    : (int) $context['customer']['source_agent_id'],
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
                ownerId: isset($context['customer']['owner_id']) ? (int) $context['customer']['owner_id'] : null,
                source: 'manual_registration',
                evidence: [
                    ...$this->evidence($this->communicationScreenshots, 'communication_screenshot'),
                    ...$this->evidence($this->settlementReceipts, 'settlement_receipt'),
                ],
                requireArrived: true,
                requireEvidence: true,
                appointmentId: isset($context['appointment']['id']) ? (int) $context['appointment']['id'] : null,
                idempotencyKey: $this->submissionKey,
                draftId: $this->draftId,
            ));
        } catch (DomainException $exception) {
            $this->fail($exception->getMessage());

            return;
        }

        $this->reset('communicationScreenshots', 'settlementReceipts');
        $this->successResult = $workspace->result($this->customerId, $orderId);
        $this->status = 'success';
        $this->draftId = null;
        $this->submissionKey = (string) Str::uuid();
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
            'communicationScreenshots' => ['required_without:settlementReceipts', 'array'],
            'communicationScreenshots.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:20480'],
            'settlementReceipts' => ['required_without:communicationScreenshots', 'array'],
            'settlementReceipts.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:20480'],
        ], [
            'communicationScreenshots.required_without' => __('orders.errors.order_evidence_required'),
            'settlementReceipts.required_without' => __('orders.errors.order_evidence_required'),
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
        $this->reset('upload', 'communicationScreenshots', 'settlementReceipts', 'errorMessage', 'successResult', 'draftId');
        $this->submissionKey = (string) Str::uuid();
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
        $context = $workspace->context($this->customerId);
        $commissionPreview = null;
        $commissionPreviewError = null;
        if ($this->institutionId !== '' && ctype_digit($this->institutionId) && ($context['can_register'] ?? false)) {
            try {
                $commissionPreview = $workspace->commissionPreview($this->customerId, (int) $this->institutionId);
            } catch (DomainException $exception) {
                $commissionPreviewError = $exception->getMessage();
            }
        }

        return view('livewire.orders.customer-order-registration', [
            'context' => $context,
            'commissionPreview' => $commissionPreview,
            'commissionPreviewError' => $commissionPreviewError,
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
        $workspace->assertVisitInstitution($this->customerId, (int) $this->institutionId);
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
