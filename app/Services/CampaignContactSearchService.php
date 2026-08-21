<?php

namespace App\Services;

use App\Models\CampaignParticipant;
use App\Models\CampaignRun;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Opportunity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Search and selection service for the "select contacts" wizard step of the
 * Campaign Run creation flow. Handles the 4 supported subject types
 * (lead, customer, contact, opportunity) with polymorphic union semantics.
 *
 * IMPORTANT: an Opportunity is a stage/attempt of a Customer or Lead. To
 * avoid confusing duplicates, opportunities are excluded from the search
 * results; the operator picks Customers/Leads/Contacts directly.
 */
class CampaignContactSearchService
{
    /**
     * @param  array<string, mixed>  $filters  See search form for keys.
     * @return Collection<int, array{subject_type:string, subject_id:int, display_name:string, company_name:?string, doc_number:?string, email:?string, phone:?string}>
     */
    public function search(array $filters, int $page = 1, int $perPage = 25): Collection
    {
        $term = trim((string) ($filters['search'] ?? ''));
        $type = $filters['type'] ?? null;
        $statusId = $filters['status_id'] ?? null;
        $sourceId = $filters['source_id'] ?? null;
        $ownerId = $filters['owner_id'] ?? null;
        $ubigeoCode = $filters['ubigeo_code'] ?? null;

        $union = collect();

if (! $type || $type === 'lead') {
            $q = Lead::query()->whereNull('deleted_at');
            $this->applyCommonFilters($q, $term, $ownerId, $ubigeoCode, ['first_name', 'last_name', 'company_name', 'doc_number', 'phone', 'email']);
            if ($statusId) {
                $q->where('status_id', $statusId);
            }
            if ($sourceId) {
                $q->where('source_id', $sourceId);
            }
            $union = $union->merge($this->mapResults($q, 'lead'));
        }

        if (! $type || $type === 'customer') {
            $q = Customer::query()->whereNull('deleted_at');
            $this->applyCommonFilters($q, $term, $ownerId, $ubigeoCode, ['legal_name', 'trade_name', 'doc_number', 'phone', 'email']);
            $union = $union->merge($this->mapResults($q, 'customer'));
        }

        if (! $type || $type === 'contact') {
            $q = Contact::query()->whereNull('deleted_at');
            $this->applyCommonFilters($q, $term, $ownerId, $ubigeoCode, ['first_name', 'last_name', 'phone', 'email']);
            $union = $union->merge($this->mapResults($q, 'contact'));
        }

        // Opportunities are NOT surfaced: an opportunity is a stage over a
        // customer/lead, so including it would risk duplicating the same
        // person. Select the underlying customer/lead instead.

        $union = $union->sortByDesc('id')->values();

        // Paginate in-memory (acceptable for ~1000 candidates; for larger
        // sets, refactor each branch to apply limit/offset at the DB level).
        $offset = max(0, ($page - 1) * $perPage);
        return $union->slice($offset, $perPage)->values();
    }

    /**
     * @param  array<int, array{subject_type:string, subject_id:int}>  $selectedKeys
     * @return Collection<int, array{subject_type:string, subject_id:int, existing_run_id?:int}>
     */
    public function detectDuplicates(CampaignRun $run, array $selectedKeys): Collection
    {
        $warnings = collect();
        foreach ($selectedKeys as $key) {
            [$type, $id] = [$key['subject_type'], (int) $key['subject_id']];
            $norm = $this->normalizedMatchFields($type, $id);
            if (! $norm) {
                continue;
            }

            $matches = collect();

            // Match against customers in the run with same doc/email/phone.
            if ($norm['doc']) {
                $matches = $matches->merge(
                    Customer::query()
                        ->whereNull('deleted_at')
                        ->where('doc_number_norm', $norm['doc'])
                        ->get(['id', 'company_name'])
                        ->map(fn ($c) => ['type' => 'customer', 'id' => $c->id, 'company_name' => $c->company_name])
                );
            }
            if ($norm['email']) {
                $matches = $matches->merge(
                    Lead::query()->whereNull('deleted_at')->where('email_norm', $norm['email'])->get(['id', 'company_name'])
                        ->map(fn ($l) => ['type' => 'lead', 'id' => $l->id, 'company_name' => $l->company_name])
                );
                $matches = $matches->merge(
                    Customer::query()->whereNull('deleted_at')->where('email_norm', $norm['email'])->get(['id', 'company_name'])
                        ->map(fn ($c) => ['type' => 'customer', 'id' => $c->id, 'company_name' => $c->company_name])
                );
            }
            if ($norm['phone']) {
                $matches = $matches->merge(
                    Lead::query()->whereNull('deleted_at')->where('phone_norm', $norm['phone'])->get(['id', 'company_name'])
                        ->map(fn ($l) => ['type' => 'lead', 'id' => $l->id, 'company_name' => $l->company_name])
                );
                $matches = $matches->merge(
                    Customer::query()->whereNull('deleted_at')->where('phone_norm', $norm['phone'])->get(['id', 'company_name'])
                        ->map(fn ($c) => ['type' => 'customer', 'id' => $c->id, 'company_name' => $c->company_name])
                );
            }

            $matches = $matches->unique(fn ($m) => $m['type'] . ':' . $m['id'])->reject(fn ($m) => $m['type'] === $type && $m['id'] === $id);

            if ($matches->isNotEmpty()) {
                $warnings->push([
                    'selected' => ['subject_type' => $type, 'subject_id' => $id],
                    'matches' => $matches->values()->all(),
                ]);
            }
        }

        return $warnings;
    }

    /**
     * @param  array<int, string>  $searchableColumns  Columns to LIKE for the term (depend on the model).
     */
    private function applyCommonFilters(Builder $q, string $term, ?int $ownerId, ?string $ubigeoCode, array $searchableColumns): void
    {
        if ($term !== '' && ! empty($searchableColumns)) {
            $like = '%' . str_replace('%', '\\%', $term) . '%';
            $q->where(function (Builder $w) use ($like, $searchableColumns) {
                foreach ($searchableColumns as $i => $column) {
                    if ($i === 0) {
                        $w->where($column, 'like', $like);
                    } else {
                        $w->orWhere($column, 'like', $like);
                    }
                }
            });
        }
        if ($ownerId) {
            $q->where('owner_id', $ownerId);
        }
        if ($ubigeoCode) {
            $q->where('ubigeo_code', $ubigeoCode);
        }
    }

    /**
     * @return Collection<int, array{subject_type:string, subject_id:int, display_name:string, company_name:?string, doc_number:?string, email:?string, phone:?string}>
     */
    private function mapResults(Builder $q, string $type): Collection
    {
        return $q->orderByDesc('id')->limit(500)->get()->map(fn (Model $m) => [
            'subject_type' => $type,
            'subject_id' => (int) $m->getKey(),
            'display_name' => $this->displayName($m, $type),
            'company_name' => $m->company_name ?? null,
            'doc_number' => $m->doc_number ?? null,
            'email' => $m->email ?? null,
            'phone' => $m->phone ?? null,
        ]);
    }

    private function displayName(Model $m, string $type): string
    {
        if ($type === 'contact') {
            $full = trim(($m->first_name ?? '') . ' ' . ($m->last_name ?? ''));
            return $full ?: sprintf('%s #%d', $type, $m->getKey());
        }

        if ($type === 'customer') {
            // legal_name es NOT NULL por la migración; trade_name es el alias comercial.
            return $m->legal_name
                ?: ($m->trade_name ?: sprintf('%s #%d', $type, $m->getKey()));
        }

        // lead
        $full = trim(($m->first_name ?? '') . ' ' . ($m->last_name ?? ''));
        if ($full !== '') {
            return $full;
        }
        // Si el lead no tiene persona (caso jurídico puro), mostrar la empresa.
        if (! empty($m->company_name)) {
            return $m->company_name;
        }
        return sprintf('%s #%d', $type, $m->getKey());
    }

    /** @return array{doc:?string, email:?string, phone:?string}|null */
    private function normalizedMatchFields(string $type, int $id): ?array
    {
        $class = match ($type) {
            'lead' => Lead::class,
            'customer' => Customer::class,
            'contact' => Contact::class,
            default => null,
        };
        if ($class === null) {
            return null;
        }
        $model = $class::query()->withTrashed()->find($id);
        if (! $model) {
            return null;
        }
        return [
            'doc' => $model->doc_number_norm ?? null,
            'email' => $model->email_norm ?? null,
            'phone' => $model->phone_norm ?? null,
        ];
    }
}
