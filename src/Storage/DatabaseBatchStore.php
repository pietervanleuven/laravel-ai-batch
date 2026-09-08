<?php

namespace AiBatch\Storage;

use AiBatch\BatchHandle;
use AiBatch\Contracts\BatchStore;
use AiBatch\Requests\RequestContext;
use AiBatch\Requests\ResolvedRequest;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

class DatabaseBatchStore implements BatchStore
{
    public function __construct(
        protected ConnectionResolverInterface $connections,
        protected ?string $connection = null,
        protected string $table = 'ai_batch_requests',
    ) {}

    public function store(BatchHandle $batch, array $requests): void
    {
        $now = Carbon::now();

        $rows = [];

        foreach ($requests as $customId => $request) {
            /** @var ResolvedRequest $request */
            $context = $request->context();

            $rows[] = [
                'batch_id' => $batch->id,
                'provider' => $batch->provider->name(),
                'custom_id' => (string) $customId,
                'invocation_id' => $context->invocationId,
                'structured' => $context->structured,
                'agent' => $context->agent,
                'model' => $context->model,
                'created_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $this->query()->upsert($chunk, ['batch_id', 'provider', 'custom_id'], ['invocation_id', 'structured', 'agent', 'model']);
        }
    }

    public function contexts(string $batchId, string $provider): array
    {
        $contexts = [];

        $this->query()
            ->where('batch_id', $batchId)
            ->where('provider', $provider)
            ->orderBy('id')
            ->each(function (object $row) use (&$contexts): void {
                $contexts[(string) $row->custom_id] = new RequestContext(
                    $row->invocation_id, (bool) $row->structured, $row->agent, $row->model,
                );
            }, 1000);

        return $contexts;
    }

    public function forget(string $batchId, string $provider): void
    {
        $this->query()->where('batch_id', $batchId)->where('provider', $provider)->delete();
    }

    protected function query(): Builder
    {
        return $this->connections->connection($this->connection)->table($this->table);
    }
}
