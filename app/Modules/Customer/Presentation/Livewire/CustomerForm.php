<?php

namespace App\Modules\Customer\Presentation\Livewire;

use App\Modules\Customer\Application\Data\CustomerProfileData;
use App\Modules\Customer\Application\Exceptions\CustomerCodeChanged;
use App\Modules\Customer\Application\Services\CustomerDirectory;
use App\Modules\Customer\Application\Services\CustomerProfileManager;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CustomerForm extends Component
{
    public ?int $customerId = null;

    public string $name = '';

    public string $gender = '';

    public string $birthDate = '';

    public string $sourceAgentId = '';

    public string $contact = '';

    public string $identityDocument = '';

    public string $projectIntention = '';

    public string $notes = '';

    public string $institutionId = '';

    public string $arrivalDate = '';

    public string $translatorName = '';

    public bool $automaticCode = true;

    public string $confirmedCode = '';

    public bool $codeConfirmed = false;

    public bool $duplicateConfirmed = false;

    public bool $sensitiveConfirmation = false;

    /** @var array<int, int> */
    public array $duplicateIds = [];

    /** @var array<string, mixed> */
    public array $options = [];

    public string $originalContact = '';

    public string $originalIdentityDocument = '';

    public function mount(CustomerDirectory $directory, ?int $customer = null): void
    {
        $this->options = $directory->options();
        $this->customerId = $customer;
        if ($customer === null) {
            $this->arrivalDate = now()->toDateString();

            return;
        }

        $profile = $directory->profile($customer);
        $this->name = (string) $profile['name'];
        $this->gender = (string) ($profile['gender'] ?? '');
        $this->birthDate = (string) ($profile['birth_date'] ?? '');
        $this->sourceAgentId = (string) ($profile['source_agent_id'] ?? '');
        $this->contact = (string) ($profile['contact'] ?? '');
        $this->identityDocument = (string) ($profile['identity_document'] ?? '');
        $this->projectIntention = (string) ($profile['project_intention'] ?? '');
        $this->notes = (string) ($profile['notes'] ?? '');
        $this->confirmedCode = (string) $profile['code'];
        $this->originalContact = $this->contact;
        $this->originalIdentityDocument = $this->identityDocument;
    }

    public function refreshCode(CustomerProfileManager $manager): void
    {
        $sourceId = (int) $this->sourceAgentId;
        if ($sourceId < 1) {
            $this->addError('confirmedCode', __('customers.form.validation.select_source'));

            return;
        }
        $this->confirmedCode = $manager->previewCode($sourceId);
        $this->codeConfirmed = false;
    }

    public function save(CustomerProfileManager $manager): mixed
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'gender' => ['nullable', 'string', 'max:16'],
            'birthDate' => ['required', 'date'],
            'sourceAgentId' => ['required', 'integer'],
            'contact' => ['required', 'string', 'max:255'],
            'identityDocument' => ['required', 'string', 'max:255'],
            'projectIntention' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
        if ($this->customerId === null) {
            $rules += [
                'institutionId' => ['required', 'integer'],
                'arrivalDate' => ['required', 'date'],
                'translatorName' => ['nullable', 'string', 'max:255'],
                'confirmedCode' => ['required', 'string', 'max:48'],
                'codeConfirmed' => ['accepted'],
            ];
        }
        $this->validate($rules);

        $duplicates = $manager->duplicateCandidateIds(
            $this->contact,
            $this->identityDocument,
            $this->customerId,
        );
        if ($duplicates !== [] && ! $this->duplicateConfirmed) {
            $this->duplicateIds = $duplicates;
            $this->addError('duplicateConfirmed', __('customers.form.validation.duplicate'));

            return null;
        }

        $profile = new CustomerProfileData(
            name: $this->name,
            gender: $this->gender === '' ? null : $this->gender,
            birthDate: CarbonImmutable::parse($this->birthDate),
            sourceAgentId: (int) $this->sourceAgentId,
            contactValue: $this->contact,
            identityDocument: $this->identityDocument,
            projectIntention: $this->projectIntention,
            notes: $this->notes === '' ? null : $this->notes,
        );
        $actorId = (int) Auth::id();

        if ($this->customerId !== null) {
            $manager->update(
                customerId: $this->customerId,
                profile: $profile,
                actorId: $actorId,
                sensitiveChangeConfirmed: $this->sensitiveConfirmation,
                ipAddress: request()->ip(),
            );
            Flux::toast(variant: 'success', text: __('customers.toasts.updated'));

            return $this->redirectRoute('customers.show', ['customer' => $this->customerId], navigate: true);
        }

        try {
            $customerId = $manager->create(
                profile: $profile,
                institutionId: (int) $this->institutionId,
                arrivalDate: CarbonImmutable::parse($this->arrivalDate),
                translatorName: $this->translatorName === '' ? null : $this->translatorName,
                actorId: $actorId,
                confirmedCode: $this->confirmedCode,
                automaticCode: $this->automaticCode,
                ipAddress: request()->ip(),
            );
        } catch (CustomerCodeChanged) {
            $this->confirmedCode = $manager->previewCode((int) $this->sourceAgentId);
            $this->codeConfirmed = false;
            $this->addError('confirmedCode', __('customers.form.validation.code_changed'));

            return null;
        }

        Flux::toast(variant: 'success', text: __('customers.toasts.created'));

        return $this->redirectRoute('customers.show', ['customer' => $customerId], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.customers.customer-form')
            ->title(__('customers.title.form'));
    }
}
